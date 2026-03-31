<?php

declare(strict_types=1);

namespace WsFramework\Channel\KVNatsBucket;

use Package\NatsClient\NatsClient;
use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Channel\BucketAbstract;
use WsFramework\Channel\SelectBucketInterface;

class KVNatsBucket extends BucketAbstract
{
    protected function __construct()
    {
        $this->status = true;
        static::$buckets[static::bucketName()] = $this;
        $this->natsClient = NatsClient::initKV();
    }

    /**
     * @param string $name
     * @return NatsKeyValueInterface
     * @throws \Throwable
     */
    public function bucket(string $name): NatsKeyValueInterface
    {
        return $this->natsClient->bucket($name);
    }

    public static function bucketInterface(): SelectBucketInterface
    {
        return static::$buckets[static::bucketName()];
    }

    protected static function config(): array
    {
        return [];
    }

    public function getStreamInfo(string $name): object
    {
        return $this->natsClient->getStreamInfo($name);
    }

    public function getConsumerInfo(string $streamName, string $consumerName): object
    {
        return $this->natsClient->getConsumerInfo($streamName, $consumerName);
    }

    public function purgeStream(string $name): void
    {
        $this->natsClient->purgeStream($name);
    }

    protected static function bucketName(): string
    {
        return 'KVNatsBucket';
    }
}