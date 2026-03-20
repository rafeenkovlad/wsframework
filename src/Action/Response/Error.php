<?php

namespace WsFramework\Action\Response;

class Error extends ResponseAbstract
{
    protected static function headers(): array
    {
        return static::defaultHeaders();
    }

    public static function getResponseName(): string
    {
        return 'error';
    }
}
