<?php

namespace WsFramework\Process;

class Worker extends \Workerman\Worker
{
    public static function getWorkers()
    {
        return static::$workers;
    }
}
