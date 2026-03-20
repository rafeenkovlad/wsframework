<?php

namespace WsFramework\Pool;

use Workerman\Connection\TcpConnection;

interface PoolConnectionInterface
{
    public static function getConnection(int $connectionId): ?TcpConnection;
}