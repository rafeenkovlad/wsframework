<?php

namespace WsFramework\GlobalData;

use WsFramework\Config\ENV;
use Exception;

abstract class GlobalDataAbstract implements DataInterface
{
    /**
     * @var Client
     */
    protected Client $client;

    /**
     * @var static[]
     */
    protected static array $globalsData;

    protected function __construct()
    {
        ENV::init();
        new Server(static::getHost(), static::getPort(), processName:  static::globalDataName());
        $this->connect();
        static::$globalsData[static::globalDataName()] = $this;
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
     * Запуск воркера
     * @return static
     */
    public static function main(): static
    {
        return new static();
    }

    /**
     * @return string
     */
    abstract protected static function globalDataName(): string;

    /**
     * @param string $key
     * @param $value
     * @return bool
     * @throws Exception
     */
    public function add(string $key, $value): bool
    {
        return $this->client->add($key, $value);
    }

    /**
     * @param string $key
     * @param mixed $oldValue
     * @param mixed $newValue
     * @return bool
     * @throws Exception
     */
    public function cas(string $key, mixed $oldValue, mixed $newValue): bool
    {
        return $this->client->cas($key, $oldValue, $newValue);
    }

    /**
     * @param string $key
     * @param int $step
     * @return bool
     * @throws Exception
     */
    public function increment(string $key, int $step = 1): bool
    {
        return $this->client->increment($key, $step);
    }


    /**
     * @param string $key
     * @return mixed
     * @throws Exception
     */
    public function get(string $key): mixed
    {
        return $this->client->__get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->client->__set($key, $value);
    }

    public function isset(string $key): bool
    {
        return $this->client->__isset($key);
    }

    public function unset(string $key): void
    {
        $this->client->__unset($key);
    }

    /**
     * @return array
     */
    abstract protected static function config(): array;

    /**
     * @return void
     */
    protected function connect(): void
    {
        $this->client = new Client(
            static::getHost(),
            static::getPort(),
        );
    }

    public static function service(): static
    {
        return static::$globalsData[static::globalDataName()];
    }
}
