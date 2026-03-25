<?php

declare(strict_types=1);

namespace WsFramework\Channel\KVNatsBucket;

use Package\NatsClient\NatsClient;
use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Channel\ChannelAbstract;
use WsFramework\Channel\SelectEventInterface;

class KVNatsBucket extends ChannelAbstract
{
    private NatsClient $natsClient;

    protected function __construct()
    {
        $this->status = true;
        static::$channels[static::channelName()] = $this;
        $this->natsClient = NatsClient::initKV();
    }

    public function bucket(string $name): NatsKeyValueInterface
    {
        return $this->natsClient->bucket($name);
    }

    protected static function channelName(): string
    {
        return 'KVNatsBucket';
    }

    public static function eventInterface(): SelectEventInterface
    {
        return static::$channels[static::channelName()];
    }

    protected static function dataInterface(): ?string
    {
        return null;
    }

    protected static function config(): array
    {
        return [];
    }
}