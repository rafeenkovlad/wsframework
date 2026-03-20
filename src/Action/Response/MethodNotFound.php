<?php

namespace WsFramework\Action\Response;

class MethodNotFound extends ResponseAbstract
{
    protected static function headers(): array
    {
        return static::defaultHeaders();
    }

    public static function getResponseName(): string
    {
        return 'methodNotFound';
    }
}
