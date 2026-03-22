<?php

declare(strict_types=1);

namespace WsFramework\Exception\S3;

use WsFramework\Exception\AbstractException;

class S3MissingContentLengthException extends AbstractException
{
    public function __construct(string $key)
    {
        parent::__construct("S3 headObject for {$key}: missing ContentLength");
    }
}
