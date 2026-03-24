<?php

namespace WsFramework\Exception;


class UseCaseException extends AbstractException
{
    public function __construct(string $message)
    {
        parent::__construct("UseCase Exception: $message");
    }
}