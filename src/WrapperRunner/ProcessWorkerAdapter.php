<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use ParaTest\Options;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

use function array_map;

/** The original CLI process transport. @internal */
final class ProcessWorkerAdapter implements WorkerAdapter
{
    private readonly InputStream $input;
    private readonly Process $process;

    /** @param list<string> $parameters */
    public function __construct(Options $options, array $parameters, int $token)
    {
        $this->input   = new InputStream();
        $this->process = new Process($parameters, $options->cwd, array_map('strval', $options->fillEnvWithTokens($token)), $this->input, null);
    }

    /** {@inheritDoc} */
    public function start(): void
    {
        $this->process->start();
    }

    /** {@inheritDoc} */
    public function write(string $command): void
    {
        $this->input->write($command);
    }

    /** {@inheritDoc} */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /** {@inheritDoc} */
    public function getWorkerCrashedException(string $test, ?Throwable $previousException = null): WorkerCrashedException
    {
        return WorkerCrashedException::fromProcess($this->process, $test, $previousException);
    }
}
