<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use Throwable;

/** The transport used by a persistent PHPUnit worker. @internal */
interface WorkerAdapter
{
    /** Start the worker. */
    public function start(): void;

    /** Send a command to the worker. */
    public function write(string $command): void;

    /** Poll the worker and drain its output. */
    public function isRunning(): bool;

    /** Describe a worker that failed before completing its assignment. */
    public function getWorkerCrashedException(string $test, ?Throwable $previousException = null): WorkerCrashedException;
}
