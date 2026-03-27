<?php

declare(strict_types=1);

namespace WsFramework\Channel\FfmpegNatsChannel;

use WsFramework\Channel\ChannelAbstract;
use WsFramework\Channel\SelectEventInterface;
use WsFramework\Enum\NatsSubject;
use Hyperf\Stringable\Str;
use Package\NatsClient\NatsClient;
use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Exception\S3\PipelineException;

class FfmpegNatsChannel extends ChannelAbstract
{
    private array $methodMap = [];
    private NatsClient $natsClient;

    /**
     * Канал как сервис без процесса — НЕ создаёт unix socket Server.
     */
    protected function __construct()
    {
        $this->status = true;
        static::$channels[static::channelName()] = $this;
        $this->natsClient = NatsClient::init(static::config());
        $this->createMethodMap();
    }

    protected static function channelName(): string
    {
        return 'FfmpegNatsChannel';
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
                'method' => NatsSubject::FFMPEG_JOB->value,
                'stream' => NatsSubject::FFMPEG_JOB->stream(),
                'name' => static::getStandardFormatName(NatsSubject::FFMPEG_JOB->value),
                'subject' => NatsSubject::FFMPEG_JOB->value,
            ],
        ];
    }

    /**
     * @param callable $callback
     * @param string $method
     * @return void
     */
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
                echo "FfmpegNatsChannel: {$e->getMessage()}\n";
                return;
            } catch (\Throwable $e) {
                echo "FfmpegNatsChannel: unhandled exception: {$e->getMessage()}\n";
                throw $e;
            }
        };
    }

    /**
     * @param $data
     * @param string $method
     * @return void
     */
    public function publish($data, string $method): void
    {
        $consumer = NatsClient::getConsumer($this->getConsumerName($method));
        NatsClient::publish($consumer, $data);
    }

    protected static function dataInterface(): ?string
    {
        return null;
    }

    /**
     * @return null
     */
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
            throw new \RuntimeException("NATS method '{$methodName}' is not configured");
        }

        return $consumerName;
    }
}
