<?php

namespace WsFramework\Action\Response;

class Ping extends ResponseAbstract
{

    public static function getResponseName(): string
    {
        return 'ping';
    }
}
