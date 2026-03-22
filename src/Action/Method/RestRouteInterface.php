<?php

declare(strict_types=1);

namespace WsFramework\Action\Method;

interface RestRouteInterface
{
    public static function restRoute(): string;

    public static function restMethod(): string;
}
