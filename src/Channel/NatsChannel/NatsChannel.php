<?php

namespace WsFramework\Channel\NatsChannel;

use Basis\Nats\Message\Msg;
use Hyperf\Stringable\Str;
use Package\NatsClient\NatsClient;
use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Channel\ChannelAbstract;
use WsFramework\Channel\SelectEventInterface;
use WsFramework\Enum\NatsSubjectEnum;
use WsFramework\Exception\S3\PipelineException;

class NatsChannel extends ChannelAbstract
{
    protected array $map;
    protected NatsClient $natsClient;
    protected ?string $subjectPrefix;


    /**
     * Предварительно добавь consumera в nats
     *  nats consumer add <streamName> <name>
     *  Канал как сервис без процесса*
     */
    protected function __construct(?string $subjectPrefix = null)
    {
        $this->status = true;
        $this->subjectPrefix = $subjectPrefix;
        $this->createMap();
        $config = $this->configWithNormalizeName();

        $this->natsClient = $this->isClearUpStreamAndConsumer()
            ? NatsClient::initInfrastructure($config)
            : NatsClient::initConsumer($config);
        static::$channels[static::channelName($subjectPrefix)] = $this;
    }

    private function isClearUpStreamAndConsumer(): bool
    {
        return is_null($this->subjectPrefix);
    }

    protected static function channelName(?string $subject = null): string
    {
        return 'NatsChannel' . $subject;
    }

    public static function eventInterface(?string $subject = null): SelectEventInterface
    {
        return static::$channels[static::channelName($subject)];
    }

    /**
     * @return array
     */
    protected static function config(): array
    {
        return NatsSubjectEnum::allConfigs();
    }

    /**
     * @throws \Throwable
     */
    public function on(callable $callback, string $method): void
    {
        $consumer = NatsClient::getConsumer($this->getConsumerName($method));
        $queue = NatsClient::getConsumerQueue($this->getConsumerName($method));
        $this->natsClient->on($consumer, $queue, $this->onException($callback));
    }

    /**
     * @param $data
     * @param string $method
     * @return void
     * @throws \Throwable
     */
    public function publish($data, string $method): void
    {
        $this->natsClient->publish($data, $this->getSubjectName($method));
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

    protected function createMap(): void
    {
        foreach (static::config() as ['name' => $name]) {
            $this->map[$name]['consumer'] = 'consumer_' . static::getStandardFormatName($name);
            $this->map[$name]['subject'] = 'subject_' . static::getStandardFormatName($name);
        }
    }

    protected static function getStandardFormatName(string $value): string
    {
        return Str::snake(preg_replace('/([^\\\\.]+)\.?([^\\\\.]+)?/s', '$1$2', $value));
    }

    protected function configWithNormalizeName(): array
    {
        $config = $this->configFilterBySubject();
        foreach ($config as $key => ['stream' => $stream, 'name' => $name]) {
            $config[$key]['stream'] = static::getStandardFormatName($stream);
            $config[$key]['consumer'] = $this->getConsumerName($name);
            $config[$key]['subject'] = $this->getSubjectName($name);
        }

        return $config;
    }

    protected function configFilterBySubject(): array
    {
        if (is_null($this->subjectPrefix)) {
            return static::config();
        }

        return array_filter(static::config(), fn($arr) => $arr['name'] === $this->subjectPrefix);
    }

    public function getConsumerName($name): string
    {
        return $this->map[$name]['consumer'];
    }

    public function getSubjectName($name): string
    {
        return $this->map[$name]['subject'];
    }

    public function onException(callable $callback): callable
    {
        return function (Msg $msg) use ($callback)
        {
            try {
                $callback($msg);
                $msg->ack();
            } catch (PipelineException $e) {
                echo "FfmpegNatsChannel: {$e->getMessage()}\n";
                $msg->ack();
            } catch (\Throwable $e) {
                echo "FfmpegNatsChannel: unhandled exception: {$e->getMessage()}\n";
                throw $e;
            }
        };
    }

    /**
     * @param string $subject
     * @return NatsChannel
     */
    public static function factoryListener(string $subject): SelectEventInterface
    {
        return new static($subject);
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
}