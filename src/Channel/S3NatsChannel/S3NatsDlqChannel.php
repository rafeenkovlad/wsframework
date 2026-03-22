<?php

declare(strict_types=1);

namespace WsFramework\Channel\S3NatsChannel;

use WsFramework\Channel\ChannelAbstract;
use WsFramework\Channel\SelectEventInterface;
use Hyperf\Stringable\Str;
use Package\NatsClient\NatsClient;
use Package\NatsClient\NatsKeyValueInterface;

class S3NatsDlqChannel extends ChannelAbstract
{
    public const METHOD_DOWNLOAD_DLQ = 's3Pipeline.download.dlq';
    public const METHOD_UPLOAD_DLQ   = 's3Pipeline.upload.dlq';

    private const DOWNLOAD_DLQ_STREAM  = 's3_download_dlq';
    private const UPLOAD_DLQ_STREAM    = 's3_upload_dlq';

    private array $methodMap = [];
    private NatsClient $natsClient;

    protected function __construct()
    {
        $this->status = true;
        static::$channels[static::channelName()] = $this;
        $this->natsClient = NatsClient::init(static::config());
        $this->createMethodMap();
    }

    protected static function channelName(): string
    {
        return 'S3NatsDlqChannel';
    }

    public static function eventInterface(): SelectEventInterface
    {
        return static::$channels[static::channelName()];
    }

    protected static function config(): array
    {
        return [
            [
                'method' => S3NatsDlqChannel::METHOD_DOWNLOAD_DLQ,
                'stream' => self::DOWNLOAD_DLQ_STREAM,
                'name' => static::getStandardFormatName(S3NatsDlqChannel::METHOD_DOWNLOAD_DLQ),
                'subject' => S3NatsDlqChannel::METHOD_DOWNLOAD_DLQ,
            ],
            [
                'method' => S3NatsDlqChannel::METHOD_UPLOAD_DLQ,
                'stream' => self::UPLOAD_DLQ_STREAM,
                'name' => static::getStandardFormatName(S3NatsDlqChannel::METHOD_UPLOAD_DLQ),
                'subject' => S3NatsDlqChannel::METHOD_UPLOAD_DLQ,
            ],
        ];
    }

    public function on(callable $callback, string $method): void
    {
        $consumer = NatsClient::getConsumer($this->getConsumerName($method));
        $queue = NatsClient::getConsumerQueue($this->getConsumerName($method));
        NatsClient::on($consumer, $queue, $callback);
    }

    public function publish($data, string $method): void
    {
        $consumer = NatsClient::getConsumer($this->getConsumerName($method));
        NatsClient::publish($consumer, $data);
    }

    protected static function dataInterface(): ?string
    {
        return null;
    }

    public static function data(): null
    {
        return null;
    }

    public function bucket(string $name): NatsKeyValueInterface
    {
        return $this->natsClient->bucket($name);
    }

    public function getStreamInfo(string $name): object
    {
        return $this->natsClient->getStreamInfo($name);
    }

    public function getConsumerInfo(string $streamName, string $consumerName): object
    {
        return $this->natsClient->getConsumerInfo($streamName, $consumerName);
    }

    private function createMethodMap(): void
    {
        foreach (static::config() as ['method' => $method, 'name' => $name]) {
            $this->methodMap[$method] = $name;
        }
    }

    private static function getStandardFormatName(string $value): string
    {
        return Str::snake(str_replace('.', '_', $value));
    }

    private function getConsumerName(string $methodName): string
    {
        $consumerName = $this->methodMap[$methodName] ?? null;
        if (!$consumerName) {
            throw new \RuntimeException("NATS method '{$methodName}' is not configured in S3NatsDlqChannel");
        }

        return $consumerName;
    }
}
