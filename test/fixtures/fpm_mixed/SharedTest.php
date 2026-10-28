<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\fpm_mixed;

use PHPUnit\Framework\TestCase;

use const PHP_SAPI;

/** @internal */
final class SharedTest extends TestCase
{
    /** Non-isolated files in the same run must still execute in FPM. */
    public function testFpm(): void
    {
        self::assertSame('fpm-fcgi', PHP_SAPI);
    }
}
