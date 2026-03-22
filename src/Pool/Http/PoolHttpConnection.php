<?php

declare(strict_types=1);

namespace WsFramework\Pool\Http;

use WsFramework\Dto\Pool\HttpConnectionDTO;
use WsFramework\Pool\PoolAbstract;
use WsFramework\Pool\PoolConnectionInterface;
use Workerman\Connection\TcpConnection;

class PoolHttpConnection extends PoolAbstract implements PoolConnectionInterface
{
    public static function getCollectionName(): string
    {
        return 'http_connection_collection';
    }

    public static function addOffset(int|string $offset = 0): void
    {
        parent::addOffset($offset);
    }

    public static function getOffset(int|string $offset): ?HttpConnectionDTO
    {
        return parent::getOffset($offset);
    }

    public static function getConnection(int $connectionId): ?TcpConnection
    {
        return static::getOffset($connectionId)?->connection;
    }

    public static function setConnection(int $connectionId, TcpConnection $connection): void
    {
        static::addOffset($connectionId);
        static::getOffset($connectionId)->connection = $connection;
    }

    public static function removeConnection(int $connectionId): void
    {
        static::unset($connectionId);
    }

    protected static function getClassDTO(): string
    {
        return HttpConnectionDTO::class;
    }
}
