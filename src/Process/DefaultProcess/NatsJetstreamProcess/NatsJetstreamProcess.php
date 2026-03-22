<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\NatsJetstreamProcess;

use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Process\DefaultProcess\DefaultProcessAbstract;
use WsFramework\Service\NatsJetstreamService\NatsJetstreamService;
use WsFramework\Service\HelpService\InjectionContainerService\NatsJetstreamServiceSymfonyContainerFactory;
use Workerman\Connection\TcpConnection;

class NatsJetstreamProcess extends DefaultProcessAbstract
{
    private static NatsJetstreamService $natsJetstreamService;

    protected static function constructor(): void
    {
        /** @var NatsJetstreamService $service */
        $service = NatsJetstreamServiceSymfonyContainerFactory::boot(HOME . '/config')
            ->get(NatsJetstreamService::class);
        static::$natsJetstreamService = $service;
    }

    protected static function setProtocol(): void
    {
        static::$protocol = 'http';
    }

    protected static function setHost(): void
    {
        static::$host = $_ENV['NATS_JETSTREAM_HTTP_HOST'];
    }

    protected static function setPort(): void
    {
        static::$port = $_ENV['NATS_JETSTREAM_HTTP_PORT'];
    }

    protected static function setCount(): void
    {
        static::$count = (int)$_ENV['NATS_JETSTREAM_COUNT_PROCESS'];
    }

    protected static function setRoute(): void
    {
        static::$route = '';
    }

    public static function setProcessName(): void
    {
        static::$nameProcess = 'NatsJetstreamProcess';
    }

    public static function onWorkerStart(): callable
    {
        return static::$natsJetstreamService->onWorkerStart();
    }

    public static function onMessage(): callable
    {
        return static::$natsJetstreamService->onMessage();
    }

    public static function onConnect(): callable
    {
        return function (TcpConnection $connection) {
            PoolHttpConnection::setConnection($connection->id, $connection);
        };
    }

    public static function onClose(): callable
    {
        return function (TcpConnection $connection) {
            PoolHttpConnection::removeConnection($connection->id);
        };
    }
}
