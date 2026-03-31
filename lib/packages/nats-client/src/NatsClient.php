<?php

declare(strict_types=1);

namespace Package\NatsClient;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Consumer\Configuration as ConsumerConfiguration;
use Basis\Nats\Message\Msg;
use Basis\Nats\Queue;
use Basis\Nats\Stream\ConsumerLimits;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
use Throwable;
use Workerman\Timer;
use WsFramework\Pool\Nats\PoolConsumer;
use WsFramework\Pool\Nats\PoolSubject;

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
     * @param array<array<array-key, string, array-key, string>> $config
     */
    private function __construct(private readonly array $config)
    {
    }

    /**
     *  Инфраструктура: соединение + cleanup + стримы, без консумеров.
     *  Для main() — один раз при старте.
     * @param array $config
     * @return static
     * @throws Throwable
     */
    public static function initInfrastructure(array $config): static
    {
        return new static($config)
            ->connection()
            ->cleanupOrphanStreams()
            ->cleanupOrphanConsumers()
            ->prepareStreamsWithSubjects();
    }

    /**
     * Только соединение + консумер, без cleanup/стримов.
     * Для factoryListener() — каждая корутина создаёт свой клиент.
     */
    public static function initConsumer(array $config): static
    {
        return new static($config)
            ->connection()
            ->initConsumers();
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
                'timeout' => 1,
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
     * @return $this
     * @throws Throwable
     */
    private function prepareStreamsWithSubjects(): static
    {
        $streamBySubjects = [];
        foreach ($this->config as ['stream' => $stream, 'subject' => $subject]) {
            $streamBySubjects[$stream][] = $subject;
        }

        echo "[prepareStreams] config: " . json_encode($this->config) . "\n";
        echo "[prepareStreams] grouped: " . json_encode($streamBySubjects) . "\n";

        foreach ($streamBySubjects as $stream => $subjects) {
            echo "[prepareStreams] creating stream '{$stream}' with subjects: " . json_encode($subjects) . "\n";
            try {
                $this->createStream($stream, $subjects);
                echo "[prepareStreams] stream '{$stream}' OK\n";
            } catch (\Throwable $e) {
                echo "[prepareStreams] stream '{$stream}' FAILED: {$e->getMessage()}\n";
                throw $e;
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    private function initConsumers(): static
    {
        foreach ($this->config as ['stream' => $stream, 'consumer' => $consumerName, 'subject' => $subjectName]) {
            $this->waitForStream($stream);

            $consumerConfig = new ConsumerConfiguration(
                $stream,
                $consumerName,
            );
            $consumerConfig->setSubjectFilter($subjectName);
            $consumer = new Consumer($this->client, $stream, $consumerName);
            if (!$consumer->exists()) {
                $consumer->create();
            }

            PoolConsumer::addOffset($consumerName);
            PoolConsumer::getOffset($consumerName)->consumer = $consumer;
            PoolConsumer::getOffset($consumerName)->queue = $consumer->getQueue();
        }

        echo "nats consumers started \n";
        return $this;
    }

    /**
     * Ждёт появления стрима (создаётся в initInfrastructure() другим процессом).
     */
    private function waitForStream(string $streamName, int $maxAttempts = 10): void
    {
        $stream = $this->client->getApi()->getStream($streamName);

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            if ($stream->exists()) {
                return;
            }
            echo "Waiting for stream '{$streamName}', attempt {$attempt}/{$maxAttempts}\n";
            Timer::sleep(min($attempt, 5));
        }

        throw new \RuntimeException("Stream '{$streamName}' not found after {$maxAttempts} attempts");
    }

    /**
     * @param string $name
     * @return Consumer
     */
    public static function getConsumer(string $name): Consumer
    {
        return PoolConsumer::getOffset($name)->consumer;
    }

    /**
     * @param string $name
     * @return Queue
     */
    public static function getConsumerQueue(string $name): Queue
    {
        return PoolConsumer::getOffset($name)->queue;
    }

    /**
     * Цикл чтения сообщений из NATS consumer.
     * Не управляет ack/nack — это ответственность callback-а.
     */
    public function on(Consumer $consumer, Queue $queue, callable $callback): void
    {
        /** @var Msg|null $msg */
        while ($msg = $queue->fetch()) {

            if ($msg->payload->isEmpty()) {
                if ($msg->replyTo) {
                    $consumer->client->publish($msg->replyTo, '');
                }
                continue;
            }

            call_user_func($callback, $msg);
        }
    }

    /**
     * @param callable $fn
     * @param int $maxAttempts
     * @return mixed
     * @throws Throwable
     */
    private static function retryConnection(callable $fn, int $maxAttempts = 5): mixed
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
                    Timer::sleep($delay);
                }
            }
        }

        throw $lastException;
    }

    /**
     * @param mixed $data
     * @param string $subject
     * @return void
     * @throws Throwable
     */
    public function publish(mixed $data, string $subject): void
    {
        static::retryConnection(
            fn() => PoolSubject::getOffset($subject)->stream->publish($subject, $data)
        );
    }

    public function bucket(string $name): NatsKeyValueInterface
    {
        if (!isset($this->buckets[$name])) {
            $this->buckets[$name] = new NatsKeyValue(
                $this->client->getApi()->getBucket($name),
            );
        }

        return $this->buckets[$name];
    }

    private function createStream(string $name, array $subjects): void
    {
        if (!array_key_exists($name, $this->streams)) {
            $stream = $this->client->getApi()->getStream($name);
            $subjects = array_values(
                array_unique([...$stream->getConfiguration()->getSubjects(), ...$subjects]),
            );
            foreach ($subjects as $subject) {
                PoolSubject::addOffset($subject);
                PoolSubject::getOffset($subject)->stream = $stream;
                PoolSubject::getOffset($subject)->subject = $subject;
            }

            $stream
                ->getConfiguration()
                ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
                ->setStorageBackend(StorageBackend::FILE)
                ->setSubjects($subjects)
                ->setConsumerLimits([
                    ConsumerLimits::MAX_ACK_PENDING => 0,
                    ConsumerLimits::INACTIVE_THRESHOLD => 0,
                ]);

            if ($stream->exists()) {
                $stream->update();
            } else {
                $stream->create();
            }
        }
    }

    /**
     * Удаляет stream'ы из NATS, которых нет в текущем конфиге.
     * Решает проблему "subjects overlap with an existing stream"
     * при переходе от multi-stream к single-stream архитектуре.
     **/
    private function cleanupOrphanStreams(): static
    {
        $existingStreams = $this->client->getApi()->getStreamNames();
        $activeStreamNames = [];
        foreach ($this->config as ['stream' => $stream]) {
            $activeStreamNames[] = $stream;
        }

        echo "[cleanup] existing streams in NATS: " . json_encode($existingStreams) . "\n";
        echo "[cleanup] active streams in config: " . json_encode(array_unique($activeStreamNames)) . "\n";

        $streamsForDelete = array_diff($existingStreams, $activeStreamNames);
        $streamsForDelete = array_filter($streamsForDelete, fn(string $name) => !str_starts_with($name, 'KV_'));
        echo "[cleanup] streams to delete: " . json_encode(array_values($streamsForDelete)) . "\n";

        foreach ($streamsForDelete as $streamDel) {
            $stream = $this->client->getApi()->getStream($streamDel);
            $stream->delete();
            echo "[cleanup] deleted orphan stream: {$streamDel}\n";
        }

        return $this;
    }

    /**
     * @return $this
     */
    private function cleanupOrphanConsumers(): static
    {
        $activeConsumerNames = [];
        foreach ($this->config as ['stream' => $stream, 'consumer' => $name]) {
            $activeConsumerNames[$stream][] = $name;
        }

        foreach ($activeConsumerNames as $streamName => $activeConsumers) {
            $streamObj = $this->client->getApi()->getStream($streamName);
            if (!$streamObj->exists()) {
                continue;
            }
            $existingConsumers = $streamObj->getConsumerNames();
            foreach ($existingConsumers as $consumerName) {
                if (!in_array($consumerName, $activeConsumers)) {
                    $consumer = $streamObj->getConsumer($consumerName);
                    $consumer->delete();
                    echo "deleted orphan consumer: {$consumerName}\n";
                }
            }
        }

        return $this;
    }

    /**
     * @param string $streamName
     * @return object
     * @throws Throwable
     */
    public function getStreamInfo(string $streamName): object
    {
        return $this->retryConnection(fn()=> $this->client->getApi()->getStream($streamName)->info());
    }

    /**
     * @param string $streamName
     * @param string $consumerName
     * @return object
     * @throws Throwable
     */
    public function getConsumerInfo(string $streamName, string $consumerName): object
    {
        return $this->retryConnection(fn()=> $this->client->getApi()->getStream($streamName)->getConsumer($consumerName)->info());
    }

    public function purgeStream(string $streamName): void
    {
        $this->retryConnection(fn() => $this->client->getApi()->getStream($streamName)->purge());
    }

    public static function initKV(): static
    {
        return new static([])->connection();
    }
}
