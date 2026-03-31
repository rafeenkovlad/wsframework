<?php

namespace WsFramework\Channel;

interface SelectBucketInterface
{
    public function getStreamInfo(string $name): object;

    public function getConsumerInfo(string $streamName, string $consumerName): object;

    public function purgeStream(string $name): void;
}