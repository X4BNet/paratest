<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use ParaTest\Options;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function addcslashes;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_executable;
use function is_file;
use function microtime;
use function php_ini_loaded_file;
use function php_ini_scanned_files;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function unlink;
use function usleep;

use const PHP_BINARY;

/** Owns an isolated FPM master and its shared OPcache for one run. @internal */
final class FpmPool
{
    private readonly Process $process;
    public readonly string $socket;

    /** Configure a private pool; preload is deliberately disabled. */
    public function __construct(Options $options, private readonly string $directory)
    {
        $this->socket = $directory . '/fpm.sock';
        if (strlen($this->socket) > 100) {
            throw new RuntimeException('FPM socket path is too long; use --tmp-dir=/tmp.');
        }

        $binary = $options->fpmBinary;
        if ($binary === null) {
            $sibling = dirname(PHP_BINARY, 2) . '/sbin/php-fpm';
            $binary  = is_executable($sibling) ? $sibling : (new ExecutableFinder())->find('php-fpm');
        }

        if ($binary === null || ! is_executable($binary)) {
            throw new RuntimeException('PHP-FPM was not found; specify --fpm-binary=/path/to/php-fpm.');
        }

        $configuration = sprintf(
            "[global]\ndaemonize = no\nerror_log = %s\n[paratest]\nlisten = %s\nlisten.mode = 0600\n" .
            "user = root\ngroup = root\npm = static\npm.max_children = %d\npm.max_requests = 1\n" .
            "clear_env = no\ncatch_workers_output = yes\ndecorate_workers_output = no\nrequest_terminate_timeout = 0\n",
            self::quote($directory . '/fpm.log'),
            self::quote($this->socket),
            $options->processes,
        );
        if (file_put_contents($directory . '/fpm.conf', $configuration) === false) {
            throw new RuntimeException('Cannot write the FPM pool configuration.');
        }

        $parameters = [$binary, '--nodaemonize', '--allow-to-run-as-root', '--fpm-config', $directory . '/fpm.conf'];
        $ini        = php_ini_loaded_file();
        if ($ini !== false) {
            $parameters[] = '-c';
            $parameters[] = $ini;
        }

        if ($ini === false && php_ini_scanned_files() === false) {
            $parameters[] = '-n';
        }

        $needsValue = false;
        foreach ($options->passthruPhp ?? [] as $parameter) {
            if ($needsValue) {
                $needsValue = false;
            } elseif ($parameter === '-c' || $parameter === '-d') {
                $needsValue = true;
            } elseif ($parameter !== '-n' && ! str_starts_with($parameter, '-c') && ! str_starts_with($parameter, '-d')) {
                throw new RuntimeException('The FPM adapter accepts only -c, -n and -d in --passthru-php.');
            }

            $parameters[] = $parameter;
        }

        if ($needsValue) {
            throw new RuntimeException('Missing PHP setting value in --passthru-php.');
        }

        // Keep the test pool independent of web preload and persistent disk caches.
        foreach (
            [
                'opcache.enable=1',
                'opcache.preload=',
                'opcache.file_cache=',
                'opcache.file_cache_only=0',
                'max_execution_time=0',
                'output_buffering=0',
                'implicit_flush=1',
                'expose_php=0',
            ] as $setting
        ) {
            $parameters[] = '-d';
            $parameters[] = $setting;
        }

        $this->process = new Process($parameters, $options->cwd, null, null, null);
    }

    /** Start FPM and fail promptly if its configuration or extensions cannot load. */
    public function start(): void
    {
        $this->process->start();
        $deadline = microtime(true) + 30;
        do {
            if (! $this->process->isRunning()) {
                throw new RuntimeException('FPM failed to start: ' . $this->diagnostics());
            }

            if (file_exists($this->socket)) {
                return;
            }

            usleep(1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out starting FPM: ' . $this->diagnostics());
    }

    /** Include pool errors in worker crash reports. */
    public function diagnostics(): string
    {
        $log = $this->directory . '/fpm.log';

        return $this->process->getErrorOutput() . (is_file($log) ? file_get_contents($log) : '');
    }

    /** Terminate the master and reap its workers before removing the private pool files. */
    public function stop(): void
    {
        $this->process->stop(1);
        foreach (['fpm.sock', 'fpm.conf', 'fpm.log'] as $file) {
            if (! file_exists($this->directory . '/' . $file)) {
                continue;
            }

            unlink($this->directory . '/' . $file);
        }
    }

    /** Escape paths for FPM's INI parser. */
    private static function quote(string $value): string
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new RuntimeException('Invalid FPM configuration path.');
        }

        return '"' . addcslashes($value, '\\"') . '"';
    }
}
