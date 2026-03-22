<?php

declare(strict_types=1);

namespace WsFramework\Action\Response;

class Pong extends ResponseAbstract
{

    public static function getResponseName(): string
    {
        return 'pong';
    }
}
