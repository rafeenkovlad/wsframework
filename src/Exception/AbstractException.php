<?php

namespace WsFramework\Exception;

use Exception;

abstract class AbstractException extends Exception
{
    /**
     * @var string
     */
    protected $message = '';
}
