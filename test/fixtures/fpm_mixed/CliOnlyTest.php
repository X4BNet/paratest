<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\fpm_mixed;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use const PHP_SAPI;

/** @internal */
#[Group('paratest-cli')]
final class CliOnlyTest extends TestCase
{
    /** Explicit CLI requirements must work without forcing PHPUnit child isolation. */
    public function testCli(): void
    {
        self::assertSame('cli', PHP_SAPI);
    }
}
