<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\fpm_mixed;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use const PHP_SAPI;

/** @internal */
final class IsolatedTest extends TestCase
{
    /** PHPUnit must launch a genuine CLI child process for isolated tests. */
    #[RunInSeparateProcess]
    public function testIsolation(): void
    {
        self::assertSame('cli', PHP_SAPI);
    }
}
