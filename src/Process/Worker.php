<?php

declare(strict_types=1);

namespace WsFramework\Process;

class Worker extends \Workerman\Worker
{
    public static function getWorkers(): array
    {
        return static::$workers;
    }
}
