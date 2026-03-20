<?php

namespace WsFramework\Action\Response;

class Ok extends ResponseAbstract
{

    protected static function headers(): array
    {
        return static::defaultHeaders();
    }

    public static function getResponseName(): string
    {
        return 'ok';
    }
}
