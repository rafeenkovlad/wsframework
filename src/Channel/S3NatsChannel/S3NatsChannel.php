<?php

declare(strict_types=1);

namespace WsFramework\Channel\S3NatsChannel;

use WsFramework\Channel\ChannelAbstract;
use WsFramework\Channel\SelectEventInterface;
use WsFramework\Enum\NatsSubject;
use Package\NatsClient\NatsClient;
use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Exception\S3\PipelineException;

class S3NatsChannel extends ChannelAbstract
{
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
        return 'S3NatsChannel';
    }

    public static function eventInterface(): SelectEventInterface
    {
        return static::$channels[static::channelName()];
    }

    /**
     * @return array
     */
    protected static function config(): array
    {
        return [
            [
                'method' => NatsSubject::S3_DOWNLOAD->value,
                'stream' => NatsSubject::S3_DOWNLOAD->stream(),
                'name' => NatsSubject::S3_DOWNLOAD->consumer(),
                'subject' => NatsSubject::S3_DOWNLOAD->value,
            ],
            [
                'method' => NatsSubject::S3_UPLOAD->value,
                'stream' => NatsSubject::S3_UPLOAD->stream(),
                'name' => NatsSubject::S3_UPLOAD->consumer(),
                'subject' => NatsSubject::S3_UPLOAD->value,
            ],
        ];
    }

    public function on(callable $callback, string $method): void
    {
        $consumer = NatsClient::getConsumer($this->getConsumerName($method));
        $queue = NatsClient::getConsumerQueue($this->getConsumerName($method));
        NatsClient::on($consumer, $queue, $this->onException($callback));
    }

    public function onException(callable $callback): callable
    {
        return function (...$args) use ($callback)
        {
            try {
                $callback(...$args);
            } catch (PipelineException $e) {
                echo "S3NatsChannel: {$e->getMessage()}\n";
                return;
            } catch (\Throwable $e) {
                echo get_class($e);
                echo "S3NatsChannel unhandled exception: {$e->getMessage()}\n";
                throw $e;
            }
        };
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

    private function getConsumerName(string $methodName): string
    {
        $consumerName = $this->methodMap[$methodName] ?? null;
        if (!$consumerName) {
            throw new \RuntimeException("NATS method '{$methodName}' is not configured");
        }

        return $consumerName;
    }
}
