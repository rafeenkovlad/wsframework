<?php

namespace WsFramework\Channel;

use Package\NatsClient\NatsClient;

abstract class BucketAbstract implements SelectBucketInterface
{
    /**
     * @var NatsClient
     */
    protected NatsClient $natsClient;

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
    protected static array $buckets;

    protected function __construct()
    {
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
    abstract protected static function bucketName(): string;

    /**
     * @return SelectBucketInterface
     */
    abstract public static function bucketInterface(): SelectBucketInterface;

    /**
     * @return bool
     */
    public static function getStatus(): bool
    {
        return (static::$buckets[static::bucketName()] ?? null)?->status ?? false;
    }

    /**
     * @return array
     */
    abstract protected static function config(): array;

}
