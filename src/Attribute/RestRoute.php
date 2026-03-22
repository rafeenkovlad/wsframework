<?php

declare(strict_types=1);

namespace WsFramework\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class RestRoute
{
    public function __construct(
        public string $method,
        public string $path,
    ) {
    }
}
