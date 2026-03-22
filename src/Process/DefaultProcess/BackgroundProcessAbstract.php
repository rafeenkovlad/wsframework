<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess;

use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Process\Worker;

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

    /**
     * @param NatsKeyValueInterface $kv
     * @param string $jobId
     * @param array $update
     * @return void
     * @throws \JsonException
     */
    public static function kvMerge(NatsKeyValueInterface $kv, string $jobId, array $update): void
    {
        $existing = $kv->get($jobId);
        $data = $existing ? (json_decode($existing, true, 512, JSON_THROW_ON_ERROR) ?: []) : [];
        $update['updatedAt'] ??= date('c');

        $kv->put($jobId, json_encode(array_merge($data, $update), JSON_THROW_ON_ERROR));
    }
}
