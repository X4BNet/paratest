<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\fpm;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;

use function getenv;
use function ini_get;
use function opcache_get_status;

use const PHP_SAPI;

/** @internal */
final class FpmTest extends TestCase
{
    /** Exercise the actual SAPI and shared cache, without a preload artifact. */
    public function testFpmAndOpcache(): void
    {
        self::assertSame('fpm-fcgi', PHP_SAPI);
        self::assertTrue(opcache_get_status(false)['opcache_enabled']);
        self::assertSame('', ini_get('opcache.preload'));
        self::assertSame('1', getenv('PARATEST'));
        self::assertNotFalse(getenv('TEST_TOKEN'));
        self::assertNotFalse(getenv('UNIQUE_TEST_TOKEN'));
        self::assertSame('fpm-custom', ini_get('highlight.comment'));
        self::assertNotFalse((new PhpExecutableFinder())->find(false));
    }
}
