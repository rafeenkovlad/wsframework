<?php

namespace WsFramework\Channel;

use WsFramework\Config\ENV;
use WsFramework\GlobalData\DataInterface;
use WsFramework\GlobalData\GlobalDataAbstract;
use Channel\Client;
use Exception;

abstract class ChannelAbstract implements SelectEventInterface
{
    /**
     * @var string
     */
    protected string $event;

    /**
     * @var array
     */
    protected array $events;


    /**
     * @var bool
     */
    protected bool $status = false;

    /**
     * @var static[]
     */
    protected static array $channels;

    protected function __construct()
    {
        ENV::init();
        new Server(static::getHost(), static::getPort(), processName:  static::channelName());
        $this->status = true;
        static::$channels[static::channelName()] = $this;
    }

    private static function getHost(): string
    {
        return 'unix://' . TMP . '/' . static::config()[0];
    }

    private static function getPort(): string
    {
        return static::config()[1];
    }

    /**
     * @return static
     */
    public static function main(): static
    {
        return new static();
    }

    /**
     * @return string
     */
    abstract protected static function channelName(): string;

    /**
     * @return SelectEventInterface
     */
    abstract public static function eventInterface(): SelectEventInterface;

    /**
     * @return ?string
     */
    abstract protected static function dataInterface(): ?string;

    /**
     * @return bool
     */
    public static function getStatus(): bool
    {
        return static::$channels[static::channelName()]->status;
    }

    /**
     * @param $data
     * @param string $method
     * @return void
     * @throws Exception
     */
    public function publish($data, string $method): void
    {
        static::connect();
        Client::publish(
            $method,
            $data,
        );
    }

    /**
     * @param callable $callback
     * @param string $method
     * @return void
     * @throws Exception
     */
    public function on(callable $callback, string $method): void
    {
        static::connect();
        Client::on($method, $this->onException($callback));
    }

    /**
     * @param callable $callback
     * @param string $method
     * @return void
     * @throws Exception
     */
    public function once(callable $callback, string $method): void
    {
        static::connect();
        Client::on(
            $method,
            $this->onException(function () use ($callback, $method) {
                Client::unsubscribe($method);
                $callback(...func_get_args());
            })
        );
    }

    /**
     * @param string $method
     * @return void
     */
    protected function warning(string $method): void
    {
        echo static::channelName() . ": {$method} событие отключено";
    }

    /**
     * @return array
     */
    abstract protected static function config(): array;

    /**
     * @throws Exception
     */
    static protected function connect(): void
    {
        Client::connect(static::getHost(), static::getPort());
    }

    /**
     * @return ?DataInterface
     */
    public static function data(): ?DataInterface
    {
        if (static::dataInterface()) {
            /** @var GlobalDataAbstract $dataClass */
            $dataClass = static::dataInterface();
            return $dataClass::service();
        }

        return null;
    }

    public static function unsubscribe(string|array $method): void
    {
        Client::unsubscribe($method);
    }

    /**
     * @param callable $callback
     * @return callable
     */
    public function onException(callable $callback): callable
    {
        return function (mixed ...$args) use ($callback)
        {
            $callback(...$args);
        };
    }

}
