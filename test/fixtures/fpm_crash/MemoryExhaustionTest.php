<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\fpm_crash;

use PHPUnit\Framework\TestCase;

use function ini_set;
use function str_repeat;

/** @internal */
final class MemoryExhaustionTest extends TestCase
{
    /** Trigger an engine fatal error that PHPUnit cannot catch. */
    public function testMemoryExhaustion(): void
    {
        ini_set('memory_limit', '32M');
        str_repeat('x', 128 * 1024 * 1024);
    }
}
