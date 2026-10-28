<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit\WrapperRunner;

use ParaTest\WrapperRunner\FpmPool;
use ParaTest\WrapperRunner\FpmWorkerAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function bin2hex;
use function dirname;
use function extension_loaded;
use function file_get_contents;
use function getenv;
use function glob;
use function is_executable;
use function mkdir;
use function preg_match;
use function random_bytes;
use function sys_get_temp_dir;

use const PHP_BINARY;

/** Tests use separate coordinators to avoid PHPUnit's global result registries. */
#[CoversClass(FpmPool::class)]
#[CoversClass(FpmWorkerAdapter::class)]
final class FpmRunnerTest extends TestCase
{
    private string $directory;

    /** Keep every generated pool artifact in a short private path under /tmp. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pt_fpm_' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0700);
    }

    /** Remove diagnostic files left by deliberately crashed workers. */
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** @param list<string> $arguments */
    private function runParaTest(string $adapter, string $fixture, array $arguments = []): Process
    {
        $root       = dirname(__DIR__, 3);
        $configured = getenv('PARATEST_FPM_BINARY');
        $binary     = $configured === false || $configured === '' ? dirname(PHP_BINARY, 2) . '/sbin/php-fpm' : $configured;
        if ($adapter === 'fpm' && ! is_executable($binary)) {
            self::markTestSkipped('A matching php-fpm is required (PARATEST_FPM_BINARY).');
        }

        $command = [
            PHP_BINARY,
            '-d',
            'zend.assertions=1',
            $root . '/bin/paratest',
            '--no-configuration',
            '--processes=2',
            '--tmp-dir=' . $this->directory,
            '--worker-adapter=' . $adapter,
        ];
        if ($adapter === 'fpm') {
            $command[] = '--fpm-binary=' . $binary;
        }

        $process = new Process([...$command, ...$arguments, $root . '/test/fixtures/' . $fixture], $root, null, null, 60);
        $process->run();
        self::assertSame([], glob($this->directory . '/paratest_*/*.sock'));
        self::assertSame([], glob($this->directory . '/paratest_*/request_*.context'));
        self::assertSame([], glob($this->directory . '/paratest_*/fpm.*'));

        return $process;
    }

    /** Verify real FastCGI execution, tokens, INI passthrough, and no preload. */
    public function testFpmExecution(): void
    {
        $process = $this->runParaTest('fpm', 'fpm', ['--passthru-php=-d highlight.comment=fpm-custom']);
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('OK (1 test, 8 assertions)', $process->getOutput());
        self::assertSame([], glob($this->directory . '/*'));
    }

    /** Run multiple wrappers per token to exercise FPM child replacement. */
    public function testRecyclingAndResultParity(): void
    {
        $cli = $this->runParaTest('process', 'common_results', ['--max-batch-size=1']);
        $fpm = $this->runParaTest('fpm', 'common_results', ['--max-batch-size=1']);
        self::assertSame($cli->getExitCode(), $fpm->getExitCode());
        preg_match('/Tests: [^\n]+/', $cli->getOutput(), $cliSummary);
        preg_match('/Tests: [^\n]+/', $fpm->getOutput(), $fpmSummary);
        self::assertNotEmpty($cliSummary);
        self::assertSame($cliSummary, $fpmSummary, $fpm->getOutput());
    }

    /** PHPUnit's isolated child processes must use the propagated CLI executable. */
    public function testProcessIsolation(): void
    {
        $process = $this->runParaTest('fpm', 'process_isolation', ['--process-isolation']);
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    /** Per-test isolation metadata must switch adapters without losing results. */
    public function testMixedAdapters(): void
    {
        $process = $this->runParaTest('fpm', 'fpm_mixed', ['--processes=1']);
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('OK (3 tests, 3 assertions)', $process->getOutput());
    }

    /** PCOV must record execution from shared OPcache and merge the worker reports. */
    public function testCoverage(): void
    {
        if (! extension_loaded('pcov')) {
            self::markTestSkipped('PCOV is required.');
        }

        $report  = $this->directory . '/coverage.xml';
        $process = $this->runParaTest('fpm', 'github/GH756', [
            '--passthru-php=-d pcov.enabled=1 -d pcov.directory=' . dirname(__DIR__, 2) . '/fixtures/github/GH756 -d opcache.optimization_level=0',
            '--coverage-filter=' . dirname(__DIR__, 2) . '/fixtures/github/GH756',
            '--coverage-clover=' . $report,
            '--cache-directory=' . $this->directory . '/cache',
        ]);
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertFileExists($report);
        $contents = file_get_contents($report);
        self::assertIsString($contents);
        self::assertStringContainsString('count="1"', $contents);
    }

    /** An invalid FPM executable must fail without hanging or retaining pool files. */
    public function testStartupFailure(): void
    {
        $process = $this->runParaTest('fpm', 'common_results/SuccessTest.php', ['--fpm-binary=/bin/false']);
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('FPM failed to start', $process->getErrorOutput());
    }

    /** Crashes must fail promptly and dispose of the pool and private context. */
    #[DataProvider('crashes')]
    public function testCrashes(string $fixture): void
    {
        $process = $this->runParaTest('fpm', $fixture);
        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('FPM worker failed', $process->getErrorOutput());
    }

    /** @return array<string, array{string}> */
    public static function crashes(): array
    {
        return [
            'exit zero' => ['exit_tests/UnitTestThatExitsSilentlyTest.php'],
            'exit nonzero' => ['exit_tests/UnitTestThatExitsLoudlyTest.php'],
            'fatal error' => ['fpm_crash/MemoryExhaustionTest.php'],
        ];
    }
}
