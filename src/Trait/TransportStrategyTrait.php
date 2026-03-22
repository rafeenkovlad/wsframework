<?php

declare(strict_types=1);

namespace WsFramework\Trait;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Dto\MethodDTO;
use Workerman\Connection\TcpConnection;

trait TransportStrategyTrait
{
    private static function dataWithHeaders(array &$data, ?array $headers): void
    {
        if ($headers) {
            $data['headers'] = $headers;
        } else {
            $data['headers'] = [];
        }
    }

    private static function dataWithPayload(array &$data, ?array $payload): void
    {
        if ($payload) {
            $data['payload'] = $payload;
        }
    }

    protected static function publishChannel(TcpConnection $connection, MethodDTO $methodDTO, string $methodClass): void
    {
        echo 'connection_id: ' . $connection->id . "\n";
        echo 'method: ' . $methodDTO->method . "\n";
        echo 'method_class: ' . $methodClass . "\n";

        /** @var MethodAbstract $methodClass */
        $methodClass::publishChannel(
            $connection->worker->id,
            $connection->id,
            $methodDTO,
        );
    }
}
