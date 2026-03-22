<?php

declare(strict_types=1);

namespace WsFramework\Action\Response;

class Ping extends ResponseAbstract
{

    public static function getResponseName(): string
    {
        return 'ping';
    }
}
