<?php

declare(strict_types=1);

namespace WsFramework\Pool;

use Workerman\Connection\TcpConnection;

interface PoolConnectionInterface
{
    public static function getConnection(int $connectionId): ?TcpConnection;
}