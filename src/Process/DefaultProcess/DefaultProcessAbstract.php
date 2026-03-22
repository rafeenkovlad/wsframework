<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess;

use Workerman\Events\Fiber;
use WsFramework\Config\ENV;
use WsFramework\Process\Worker;

abstract class DefaultProcessAbstract implements WorkerInterface
{
    /**
     * @var string
     */
    protected static string $nameProcess;

    /**
     * @var string
     */
    protected static string $route;

    /**
     * @var string
     */
    protected static string $protocol;

    /**
     * @var string
     */
    protected static string $host;

    /**
     * @var string
     */
    protected static string $port;

    /**
     * Кол-во процессов
     * @var int
     */
    protected static int $count;

    /**
     * @var Worker
     */
    protected static Worker $worker;

    /**
     * @var Worker[]
     */
    protected static array $workers;

    /**
     * @var string|null
     */
    protected static ?string $eventLoop;


    /**
     * @return void
     */
    abstract protected static function constructor(): void;

    /**
     * @return $this
     */
    public function init(): static
    {
        ENV::init();
        static::constructor();
        static::setProtocol();
        static::setHost();
        static::setPort();
        static::setCount();
        static::setRoute();
        static::setProcessName();
        static::setEventLoop();
        static::initWorker();
        static::setOnWorkerStart();
        static::setOnConnect();
        static::setOnMessage();
        static::setOnClose();

        return $this;
    }

    /**
     * @return void
     */
    abstract protected static function setProtocol(): void;

    /**
     * @return void
     */
    abstract protected static function setHost(): void;

    /**
     * @return void
     */
    abstract protected static function setPort(): void;

    /**
     * @return void
     */
    abstract protected static function setCount(): void;


    /**
     * @return void
     */
    abstract protected static function setRoute(): void;

    /**
     * @return void
     */
    abstract public static function setProcessName(): void;

    /**
     * @return void
     */
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
     * @return void
     */
    private static function setOnConnect(): void
    {
        if (method_exists(static::class, 'onConnect')) {
            static::$workers[static::$nameProcess]->onConnect = static::onConnect();
        }
    }

    /**
     * @return void
     */
    private static function setOnMessage(): void
    {
        if (method_exists(static::class, 'onMessage')) {
            static::$workers[static::$nameProcess]->onMessage = static::onMessage();
        }
    }

    /**
     * @return void
     */
    private static function setOnClose(): void
    {
        if (method_exists(static::class, 'onClose')) {
            static::$workers[static::$nameProcess]->onClose = static::onClose();
        }
    }

    private static function setOnWorkerStart(): void
    {
        if (method_exists(static::class, 'onWorkerStart')) {
            static::$workers[static::$nameProcess]->onWorkerStart = static::onWorkerStart();
        }
    }

    /**
     * @return Worker
     */
    public static function getWorker(): Worker
    {
        return static::$worker;
    }

    /**
     * @return void
     */
    protected static function setEventLoop(): void
    {
        static::$eventLoop = Fiber::class;
    }

}
