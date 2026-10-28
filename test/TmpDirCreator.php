<?php

declare(strict_types=1);

namespace ParaTest\Tests;

use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem;

use function getenv;
use function glob;

use const DIRECTORY_SEPARATOR;

final readonly class TmpDirCreator
{
    /** @return non-empty-string */
    public function create(): string
    {
        $configured = getenv('PARATEST_TEST_TMP_DIR');
        $base       = $configured === false || $configured === '' ? __DIR__ . DIRECTORY_SEPARATOR . 'tmp' : $configured;
        $tmpDir     = $base . DIRECTORY_SEPARATOR . 'token_' . (string) getenv('TEST_TOKEN');

        $glob = glob($tmpDir . DIRECTORY_SEPARATOR . '*');
        Assert::assertNotFalse($glob);

        (new Filesystem())->remove($glob);
        (new Filesystem())->mkdir($tmpDir);

        return $tmpDir;
    }
}
