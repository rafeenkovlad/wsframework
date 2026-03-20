<?php

namespace WsFramework\Service;

use Workerman\Worker;

interface ChannelListenServiceInterface
{
    /**
     * @param Worker $worker
     * @return void
     */
    public static function main(Worker $worker): void;
}
