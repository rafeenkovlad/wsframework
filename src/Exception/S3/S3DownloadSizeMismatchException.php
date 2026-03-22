<?php

declare(strict_types=1);

namespace WsFramework\Exception\S3;

use WsFramework\Exception\AbstractException;

class S3DownloadSizeMismatchException extends AbstractException
{
    public function __construct(string $key, int $expected, int|false $actual)
    {
        $actualStr = $actual === false ? 'unknown' : (string) $actual;
        parent::__construct("S3 download size mismatch for {$key}: expected {$expected}, got {$actualStr}");
    }
}
