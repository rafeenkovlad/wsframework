<?php

namespace WsFramework\Middleware;

use WsFramework\Action\Response\Error;
use WsFramework\Dto\MethodDTO;
use WsFramework\Pool\PoolAbstract;
use Workerman\Connection\TcpConnection;

class RateLimit
{
    public static function isLimitMax(string $poolClass, int $connectionId, MethodDTO $methodDTO, int $maxRequests): bool
    {
        /** @var  PoolAbstract $poolClass */
        $pool = $poolClass::getOffset($connectionId);

        if (!$pool || !$pool->connection || $maxRequests <= 0) {
            return false;
        }

        $now = time();
        if ($pool->requestMinuteStart === null || ($now - $pool->requestMinuteStart) >= 60) {
            $pool->requestMinuteStart = $now;
            $pool->requestCountMinute = 1;
            return false;
        }

        $pool->requestCountMinute = ($pool->requestCountMinute ?? 0) + 1;

        if ($pool->requestCountMinute > $maxRequests) {
            static::sendResponseError($pool->connection, $methodDTO, $maxRequests);
            return true;
        }

        return false;
    }

    private static function sendResponseError(TcpConnection $connection, MethodDTO $methodDTO, int $maxRequests): void
    {
        $methodDTO->response->errors = [
            'message' => 'too many requests',
            'limit' => $maxRequests,
        ];
        Error::apply($connection, $methodDTO->response);
    }
}
