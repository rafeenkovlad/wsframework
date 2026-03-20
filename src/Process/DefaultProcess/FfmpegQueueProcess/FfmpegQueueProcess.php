<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\FfmpegQueueProcess;

use WsFramework\Process\DefaultProcess\DefaultProcessAbstract;
use WsFramework\Service\FfmpegQueueService\FfmpegQueueService;
use WsFramework\Service\HelpService\InjectionContainerService\FfmpegQueueServiceSymfonyContainerFactory;
use Workerman\Connection\TcpConnection;

class FfmpegQueueProcess extends DefaultProcessAbstract
{
    private static FfmpegQueueService $ffmpegQueueService;

    protected static function constructor(): void
    {
        /** @var FfmpegQueueService $service */
        $service = FfmpegQueueServiceSymfonyContainerFactory::boot(HOME . '/config')
            ->get(FfmpegQueueService::class);
        static::$ffmpegQueueService = $service;
    }

    protected static function setProtocol(): void
    {
        static::$protocol = $_ENV['FFMPEG_QUEUE_PROTOCOL'];
    }

    protected static function setHost(): void
    {
        static::$host = $_ENV['FFMPEG_QUEUE_HOST'];
    }

    protected static function setPort(): void
    {
        static::$port = $_ENV['FFMPEG_QUEUE_PORT'];
    }

    protected static function setCount(): void
    {
        static::$count = (int)$_ENV['FFMPEG_QUEUE_COUNT_PROCESS'];
    }

    protected static function setRoute(): void
    {
        static::$route = '';
    }

    public static function setProcessName(): void
    {
        static::$nameProcess = 'FfmpegQueueProcess';
    }

    public static function onWorkerStart(): callable
    {
        return static::$ffmpegQueueService->onWorkerStart();
    }

    public static function onMessage(): callable
    {
        return static::$ffmpegQueueService->onMessage();
    }

    public static function onConnect(): callable
    {
        return function (TcpConnection $connection) {
        };
    }

    public static function onClose(): callable
    {
        return function (TcpConnection $connection) {
        };
    }
}
