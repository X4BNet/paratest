<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use ParaTest\Options;
use RuntimeException;
use Throwable;

use function array_merge;
use function array_search;
use function array_slice;
use function bin2hex;
use function chmod;
use function chr;
use function dirname;
use function fclose;
use function feof;
use function file_exists;
use function file_put_contents;
use function fwrite;
use function getenv;
use function is_resource;
use function microtime;
use function ord;
use function pack;
use function random_bytes;
use function serialize;
use function sprintf;
use function str_repeat;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_server;
use function strlen;
use function substr;
use function unlink;

use const PHP_VERSION_ID;

/** One persistent wrapper request, sent directly to the private pool over FastCGI. @internal */
final class FpmWorkerAdapter implements WorkerAdapter
{
    /** @var resource|null */
    private $connection = null;
    /** @var resource|null */
    private $listener = null;
    /** @var resource|null */
    private $commands        = null;
    private string $pending  = '';
    private string $received = '';
    private string $output   = '';
    private string $error    = '';
    private bool $running    = false;
    private float $started   = 0;
    private readonly string $context;
    private readonly string $commandSocket;

    /** @param list<string> $parameters */
    public function __construct(
        private readonly FpmPool $pool,
        private readonly Options $options,
        array $parameters,
        int $token,
        string $directory,
    ) {
        $id                  = bin2hex(random_bytes(6));
        $this->context       = $directory . '/request_' . $id . '.context';
        $this->commandSocket = $directory . '/cmd_' . $id . '.sock';
        if (strlen($this->commandSocket) > 100) {
            throw new RuntimeException('FPM command socket path is too long; use --tmp-dir=/tmp.');
        }

        $wrapper = dirname(__DIR__, 2) . '/bin/phpunit-wrapper.php';
        $index   = array_search($wrapper, $parameters, true);
        if ($index === false) {
            throw new RuntimeException('Cannot locate the PHPUnit worker entry point.');
        }

        $environment = array_merge(getenv(), $options->fillEnvWithTokens($token), ['PHP_PEAR_PHP_BIN' => $parameters[0]]);
        $context     = serialize([
            'version' => PHP_VERSION_ID,
            'arguments' => array_slice($parameters, $index + 1),
            'environment' => $environment,
            'cwd' => $options->cwd,
            'commands' => $this->commandSocket,
        ]);
        if (file_put_contents($this->context, $context) === false) {
            throw new RuntimeException('Cannot write the FPM worker context.');
        }

        chmod($this->context, 0600);
    }

    /** Open a command channel and submit a genuine FastCGI responder request. */
    public function start(): void
    {
        $listener = @stream_socket_server('unix://' . $this->commandSocket, $errno, $error);
        if ($listener === false) {
            throw new RuntimeException('Cannot open the FPM command socket: ' . $error);
        }

        $this->listener = $listener;
        chmod($this->commandSocket, 0600);
        stream_set_blocking($this->listener, false);
        $connection = @stream_socket_client('unix://' . $this->pool->socket, $errno, $error, 10);
        if ($connection === false) {
            throw new RuntimeException('Cannot connect to FPM: ' . $error . "\n" . $this->pool->diagnostics());
        }

        $this->connection = $connection;
        stream_set_timeout($connection, 10);
        $parameters = '';
        foreach (
            [
                'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/bin/phpunit-fpm.php',
                'REQUEST_METHOD' => 'POST',
                'CONTENT_LENGTH' => '0',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'GATEWAY_INTERFACE' => 'CGI/1.1',
                'PARATEST_CONTEXT' => $this->context,
            ] as $name => $value
        ) {
            $parameters .= self::encodeLength(strlen($name)) . self::encodeLength(strlen($value)) . $name . $value;
        }

        $request = self::record(1, pack('nCxxxxx', 1, 0)) . self::record(4, $parameters) . self::record(4, '') . self::record(5, '');
        while ($request !== '') {
            $written = fwrite($this->connection, $request);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot send the FastCGI worker request.');
            }

            $request = substr($request, $written);
        }

        stream_set_blocking($this->connection, false);
        $this->started = microtime(true);
        $this->running = true;
    }

    /** Queue commands until the FPM child has connected to its command channel. */
    public function write(string $command): void
    {
        $this->pending .= $command;
    }

    /** Drain FastCGI output and forward commands without blocking the scheduler. */
    public function isRunning(): bool
    {
        if (! $this->running) {
            return false;
        }

        if (! is_resource($this->connection) || ! is_resource($this->listener)) {
            throw new RuntimeException('FPM worker was not started.');
        }

        if ($this->commands === null) {
            $commands = @stream_socket_accept($this->listener, 0);
            if ($commands !== false) {
                $this->commands = $commands;
                stream_set_blocking($commands, false);
            } elseif (microtime(true) - $this->started > 30) {
                $this->error .= 'Timed out waiting for the FPM worker command channel.';

                return $this->running = false;
            }
        }

        if ($this->commands !== null && $this->pending !== '') {
            $written = @fwrite($this->commands, $this->pending);
            if ($written === false) {
                $this->error .= 'FPM worker command channel closed unexpectedly.';

                return $this->running = false;
            }

            $this->pending = substr($this->pending, $written);
        }

        $bytes = stream_get_contents($this->connection, 1048576);
        if ($bytes === false) {
            $this->error .= 'Cannot read the FastCGI response.';

            return $this->running = false;
        }

        $this->received .= $bytes;
        while (strlen($this->received) >= 8) {
            $version    = ord($this->received[0]);
            $type       = ord($this->received[1]);
            $request    = ord($this->received[2]) * 256 + ord($this->received[3]);
            $bodyLength = ord($this->received[4]) * 256 + ord($this->received[5]);
            $length     = 8 + $bodyLength + ord($this->received[6]);
            if (strlen($this->received) < $length) {
                break;
            }

            if ($version !== 1 || $request !== 1) {
                $this->error .= 'Invalid FastCGI response header.';

                return $this->running = false;
            }

            $body           = substr($this->received, 8, $bodyLength);
            $this->received = substr($this->received, $length);
            if ($type === 6) {
                $this->output = substr($this->output . $body, -1048576);
            } elseif ($type === 7) {
                $this->error = substr($this->error . $body, -1048576);
            } elseif ($type === 3) {
                $this->running = false;
                if (strlen($body) !== 8 || substr($body, 0, 5) !== str_repeat("\0", 5)) {
                    $this->error .= 'FPM returned a failed FastCGI END_REQUEST: ' . bin2hex($body);
                }

                break;
            }
        }

        if (feof($this->connection) && $this->running) {
            $this->error  .= 'FPM closed the connection without END_REQUEST.';
            $this->running = false;
        }

        return $this->running;
    }

    /** Include the FastCGI response and FPM log in a crash report. */
    public function getWorkerCrashedException(string $test, ?Throwable $previousException = null): WorkerCrashedException
    {
        return new WorkerCrashedException(sprintf(
            "FPM worker failed while executing \"%s\".\n\nWorking directory: %s\n\nOutput:\n%s\n\nError Output:\n%s\n%s",
            $test,
            $this->options->cwd,
            $this->output,
            $this->error,
            $this->pool->diagnostics(),
        ), 0, $previousException);
    }

    /** Close sockets and remove the worker's private environment and command channel. */
    public function __destruct()
    {
        foreach ([$this->commands, $this->connection, $this->listener] as $stream) {
            if (! is_resource($stream)) {
                continue;
            }

            fclose($stream);
        }

        foreach ([$this->context, $this->commandSocket] as $path) {
            if (! file_exists($path)) {
                continue;
            }

            unlink($path);
        }
    }

    /** @param non-negative-int $length Encode a FastCGI name/value length, including the four-byte form. */
    private static function encodeLength(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    /** Frame a record for request 1 on a connection dedicated to one worker. */
    private static function record(int $type, string $body): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body;
    }
}
