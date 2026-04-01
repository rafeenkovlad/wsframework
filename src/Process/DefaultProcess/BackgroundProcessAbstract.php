<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess;

use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\KVMergeOptionsDTO;
use WsFramework\Enum\JobType;
use WsFramework\Process\Worker;
use WsFramework\UseCase\JobKVMergeUseCase;

abstract class BackgroundProcessAbstract extends DefaultProcessAbstract
{

    protected static function setProtocol(): void
    {
    }

    protected static function setHost(): void
    {
    }

    protected static function setPort(): void
    {
    }

    protected static function setRoute(): void
    {
    }

    public static function onConnect(): callable
    {
        return function () {
        };
    }

    public static function onMessage(): callable
    {
        return function () {
        };
    }

    public static function onClose(): callable
    {
        return function () {
        };
    }

    protected static function initWorker(): void
    {
        static::$worker = new Worker(static::getSocketName());
        static::$worker->name = static::$nameProcess;
        static::$worker->count = static::$count;
        static::$worker->eventLoop = static::$eventLoop;
        static::$workers[static::$nameProcess] = static::$worker;
    }

    /**
     * @return string
     */
    private static function getSocketName(): string
    {
        return (match (static::$protocol) {
            'unix' => fn() => static::$protocol . '://' . TMP . '/' . static::$host,
            'websocket',
            'tcp',
            'http', => fn() => static::$protocol . '://' . static::$host . ':' . static::$port . static::$route,
        })();
    }

    public static function kvMerge(JobKVDTO $update, ?JobType $jobType = null): void
    {
        JobKVMergeUseCase::handle(
            $update,
            KVMergeOptionsDTO::createFromArray(
                ['jobType' => $jobType]
            )
        );
    }
}
