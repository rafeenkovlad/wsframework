<?php

declare(strict_types=1);

namespace Package\NatsClient;

use JsonException;
use WsFramework\Pool\Nats\PoolConsumer;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Consumer\Configuration as ConsumerConfiguration;
use Basis\Nats\Message\Msg;
use Basis\Nats\Queue;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
use RuntimeException;

class NatsClient
{
    readonly private ?Client $client;
    /**
     * @var array<string, NatsKeyValueInterface>
     */
    private array $buckets = [];
    /**
     * @var array<array-key,Stream>
     */
    private array $streams = [];

    /**
     * @param array<int, array{stream: string, name: string, subject?: string}> $consumers
     */
    private function __construct(private array $consumers)
    {
    }

    /**
     * @param array $consumers
     * @return void
     */
    public static function init(array $consumers): static
    {
        $init = new static($consumers);
        return $init
            ->connection()
            ->retryConnection(fn()=> $init->initConsumers());
    }

    private function connection(): static
    {
        $configuration = new Configuration(
            [
                'host' => $_ENV['APP_NATS_HOST'],
                'jwt' => null,
                'lang' => 'php',
                'pass' => null,
                'pedantic' => false,
                'port' => (int)$_ENV['APP_NATS_PORT'],
                'reconnect' => true,
                'timeout' => 5,
                'token' => null,
                'user' => null,
                'nkey' => null,
                'verbose' => false,
            ],
        );

        $this->client = new Client($configuration);
        echo "nats client started \n";
        return $this;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @param int $maxAttempts
     * @return T
     */
    private function retryConnection(callable $fn, int $maxAttempts = 5): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $lastException = $e;
                echo "Nats connection failed, attempt: {$attempt}/{$maxAttempts} — {$e->getMessage()}\n";

                if ($attempt < $maxAttempts) {
                    $delay = min($attempt * $attempt, 10);
                    sleep($delay);
                }
            }
        }

        throw $lastException;
    }

    /**
     * @return $this
     */
    private function initConsumers(): static
    {
        foreach ($this->consumers as ['stream' => $stream, 'name' => $name, 'subject' => $subject]) {
            $subject ??= $name;

            $this->createStream($stream, $subject);
            $consumerConfig = new ConsumerConfiguration(
                $stream,
                $name,
            );
            $consumerConfig->setSubjectFilter($subject);
            $consumer = new Consumer($this->client, $consumerConfig);
            $consumer->create();

            PoolConsumer::addOffset($name);
            PoolConsumer::getOffset($name)->consumer = $consumer;
            PoolConsumer::getOffset($name)->queue = $consumer->getQueue();
        }

        echo "nats consumers started \n";
        return $this;
    }

    /**
     * @param string $name
     * @return Consumer
     */
    public static function getConsumer(string $name): Consumer
    {
        $consumer = PoolConsumer::getOffset($name)?->consumer;
        if (!$consumer) {
            throw new RuntimeException("NATS consumer '{$name}' is not initialized");
        }

        return $consumer;
    }

    /**
     * @param string $name
     * @return Queue
     */
    public static function getConsumerQueue(string $name): Queue
    {
        $queue = PoolConsumer::getOffset($name)?->queue;
        if (!$queue) {
            throw new RuntimeException("NATS queue for consumer '{$name}' is not initialized");
        }

        return $queue;
    }

    /**
     * @param Consumer $consumer
     * @param Queue $queue
     * @param callable $callback
     * @return void
     */
    public static function on(Consumer $consumer, Queue $queue, callable $callback): void
    {
        /** @var Msg|null $msg */
        while ($msg = $queue->fetch()) {

            if ($msg->payload->isEmpty()) {
                if ($msg->replyTo) {
                    $consumer->client->publish($msg->replyTo, '');
                }
                continue;
            }

            try {
                call_user_func($callback, $msg);
                $msg->ack();
            } catch (JsonException $e) {
                echo "NATS handler json error: {$e->getMessage()}\n";
                echo "NATS message render:  {$msg->render()}\n";
                $msg->ack();
            }
            catch (\Throwable $e) {
                echo "NATS handler error: {$e->getMessage()}\n";
                echo "NATS message render:  {$msg->render()}\n";
                $msg->nack((float)($_ENV['NATS_DELAY_NACK_IN_LOOP'] ?? 5000000));
            }
        }
    }

    /**
     * @param Consumer $consumer
     * @param mixed $data
     * @return void
     */
    public static function publish(Consumer $consumer, mixed $data): void
    {
        $subject = $consumer->getConfiguration()->getSubjectFilter() ?? $consumer->getName();
        $consumer->client->publish($subject, $data);
    }

    public function bucket(string $name): NatsKeyValueInterface
    {
        if (!isset($this->buckets[$name])) {
            $this->buckets[$name] = new NatsKeyValue(
                $this->retryConnection(fn()=> $this->client->getApi()->getBucket($name)),
            );
        }

        return $this->buckets[$name];
    }

    public function getStreamInfo(string $streamName): object
    {
        return $this->retryConnection(fn()=> $this->client->getApi()->getStream($streamName)->info());
    }

    public function getConsumerInfo(string $streamName, string $consumerName): object
    {
        return $this->retryConnection(fn()=> $this->client->getApi()->getStream($streamName)->getConsumer($consumerName)->info());
    }

    private function createStream(string $name, string $subject): void
    {
        if (!array_key_exists($name, $this->streams)) {
            $this->streams[$name] = $this->retryConnection(fn()=> $this->client->getApi()->getStream($name));
        }

        $stream = &$this->streams[$name];
        $configuration = $stream
            ->getConfiguration()
            ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
            ->setStorageBackend(StorageBackend::FILE);

        $subjects = $configuration->getSubjects();
        if (!in_array($subject, $subjects, true)) {
            $configuration->setSubjects(array_values(array_unique([...$subjects, $subject])));
        }

        if (!$stream->exists()) {
            $stream->createIfNotExists();
            return;
        }

        if (!in_array($subject, $subjects, true)) {
            $stream->update();
        }
    }
}
