<?php

declare(strict_types=1);

namespace WsFramework\Exception\S3;

use WsFramework\Exception\AbstractException;

class S3MissingConfigException extends AbstractException
{
    public function __construct(string $envVar)
    {
        parent::__construct("{$envVar} env variable is required");
    }
}
