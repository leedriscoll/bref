<?php declare(strict_types=1);

namespace Bref\Runtime;

use Bref\Http\LambdaResponse;
use Hoa\Fastcgi\Responder;
use Hoa\Socket\Client;
use Symfony\Component\Process\Process;

/**
 * Proxies HTTP events coming from API Gateway to PHP-FPM via FastCGI.
 *
 * Usage example:
 *
 *     $event = [get the Lambda event];
 *     $phpFpm = new PhpFpm('index.php');
 *     $phpFpm->start();
 *     $lambdaResponse = $phpFpm->proxy($event);
 *     $phpFpm->stop();
 *     [send the $lambdaResponse];
 */
class PhpFpm
{
    private const SOCKET = '/tmp/.bref/php-fpm.sock';
    private const CONFIG = '/opt/bref/etc/php-fpm.conf';

    /** @var Responder */
    private $client;
    /** @var string */
    private $handler;
    /** @var string */
    private $configFile;
    /** @var Process|null */
    private $fpm;

    public function __construct(string $handler, string $configFile = self::CONFIG)
    {
        $this->client = new Responder(
            new Client('unix://' . self::SOCKET, 30000)
        );
        $this->handler = $handler;
        $this->configFile = $configFile;
    }

    /**
     * Start the PHP-FPM process.
     */
    public function start(): void
    {
        if (! is_dir(dirname(self::SOCKET))) {
            mkdir(dirname(self::SOCKET));
        }

        /**
         * --nodaemonize: we want to keep control of the process
         * --force-stderr: force logs to be sent to stderr, which will allow us to send them to CloudWatch
         */
        $this->fpm = new Process(['php-fpm', '--nodaemonize', '--force-stderr', '--fpm-config', $this->configFile]);
        $this->fpm->setTimeout(null);
        $this->fpm->start(function ($type, $output): void {
            // Send any PHP-FPM log to CloudWatch
            echo $output;
        });

        $this->waitForServerReady();
    }

    public function stop(): void
    {
        if ($this->fpm && $this->fpm->isRunning()) {
            $this->fpm->stop();
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @throws \Exception If the PHP-FPM process is not running anymore.
     */
    public function ensureStillRunning(): void
    {
        if (! $this->fpm || ! $this->fpm->isRunning()) {
            throw new \Exception('PHP-FPM has stopped for an unknown reason');
        }
    }

    /**
     * Proxy the API Gateway event to PHP-FPM and return its response.
     *
     * @param mixed $event
     */
    public function proxy($event): LambdaResponse
    {
        if (! isset($event['httpMethod'])) {
            throw new \Exception('The lambda was not invoked via HTTP through API Gateway: this is not supported by this runtime');
        }

        [$requestHeaders, $requestBody] = $this->eventToFastCgiRequest($event);

        $this->client->send($requestHeaders, $requestBody);

        $responseHeaders = $this->client->getResponseHeaders();

        $responseHeaders = array_change_key_case($responseHeaders, CASE_LOWER);

        /**
         * In some cases PHP-FPM sends logs into the FastCGI connection. That isn't valid and isn't supported
         * by our FastCGI client library, so those logs end up mangled in the HTTP headers.
         * We can't return them to the client as:
         * - the response is broken
         * - it contains internal logs
         * Until we find a better solution we simply return an empty 500 response, because since there were logs sent
         * through FastCGI something wrong must have happened anyway. Those logs will also be sent to CloudWatch
         * so debugging is possible.
         *
         * @see https://github.com/mnapoli/bref/pull/216
         */
        if (isset($responseHeaders['php message'])) {
            return new LambdaResponse(500, [], '');
        }

        // Extract the status code
        if (isset($responseHeaders['status'])) {
            [$status] = explode(' ', $responseHeaders['status']);
        } else {
            $status = 200;
        }
        unset($responseHeaders['status']);

        $responseBody = $this->client->getResponseContent();

        return new LambdaResponse((int) $status, $responseHeaders, $responseBody);
    }

    private function waitForServerReady(): void
    {
        $wait = 5000; // 5ms
        $timeout = 5000000; // 5 secs
        $elapsed = 0;

        while (! $this->isReady()) {
            usleep($wait);
            $elapsed += $wait;

            if ($elapsed > $timeout) {
                throw new \Exception('Timeout while waiting for PHP-FPM socket at ' . self::SOCKET);
            }

            // If the process has crashed we can stop immediately
            if (! $this->fpm->isRunning()) {
                throw new \Exception('PHP-FPM failed to start');
            }
        }
    }

    private function isReady(): bool
    {
        clearstatcache(false, self::SOCKET);

        return file_exists(self::SOCKET);
    }

    private function eventToFastCgiRequest(array $event): array
    {
        $requestBody = $event['body'] ?? '';
        if ($event['isBase64Encoded'] ?? false) {
            $requestBody = base64_decode($requestBody);
        }

        $uri = $event['path'] ?? '/';
        /*
         * queryStringParameters does not handle correctly arrays in parameters
         * ?array[key]=value gives ['array[key]' => 'value'] while we want ['array' => ['key' = > 'value']]
         * We recreate the original query string and we use parse_str which handles correctly arrays
         *
         * There's still an issue: AWS API Gateway does not support multiple query string parameters with the same name
         * So you can't use something like ?array[]=val1&array[]=val2 because only the 'val2' value will survive
         */
        $queryString = http_build_query($event['queryStringParameters'] ?? []);
        parse_str($queryString, $queryParameters);
        if (! empty($queryString)) {
            $uri .= '?' . $queryString;
        }
        $queryString = http_build_query($queryParameters);

        $protocol = $event['requestContext']['protocol'] ?? 'HTTP/1.1';

        // Normalize headers
        $headers = $event['headers'] ?? [];
        $headers = array_change_key_case($headers, CASE_LOWER);

        $serverName = $headers['host'] ?? 'localhost';

        $requestHeaders = [
            'GATEWAY_INTERFACE' => 'FastCGI/1.0',
            'REQUEST_METHOD' => $event['httpMethod'],
            'REQUEST_URI' => $uri,
            'SCRIPT_FILENAME' => $this->handler,
            'SERVER_SOFTWARE' => 'bref',
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_PORT' => $headers['X-Forwarded-Port'] ?? 80,
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => $serverName,
            'SERVER_PROTOCOL' => $protocol,
            'SERVER_PORT' => $headers['X-Forwarded-Port'] ?? 80,
            'PATH_INFO' => $event['path'] ?? '/',
            'QUERY_STRING' => $queryString,
        ];

        // See https://stackoverflow.com/a/5519834/245552
        if ((strtoupper($event['httpMethod']) === 'POST') && ! isset($headers['content-type'])) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }
        if (isset($headers['content-type'])) {
            $requestHeaders['CONTENT_TYPE'] = $headers['content-type'];
        }
        // Auto-add the Content-Length header if it wasn't provided
        // See https://github.com/mnapoli/bref/issues/162
        if ((strtoupper($event['httpMethod']) === 'POST') && ! isset($headers['content-length'])) {
            $headers['content-length'] = strlen($requestBody);
        }
        if (isset($headers['content-length'])) {
            $requestHeaders['CONTENT_LENGTH'] = $headers['content-length'];
        }

        foreach ($headers as $header => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            $requestHeaders[$key] = $value;
        }

        return [$requestHeaders, $requestBody];
    }
}
