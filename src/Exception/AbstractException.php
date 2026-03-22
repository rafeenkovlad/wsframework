<?php

declare(strict_types=1);

namespace WsFramework\Exception;

use Exception;

abstract class AbstractException extends Exception
{
    /**
     * @var string
     */
    protected $message = '';
}
