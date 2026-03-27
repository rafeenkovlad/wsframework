<?php

declare(strict_types=1);

namespace Package\NatsClient;

use Basis\Nats\KeyValue\Bucket;
use Basis\Nats\KeyValue\Entry;
use Basis\Nats\KeyValue\Status;
use Workerman\Timer;

readonly class NatsKeyValue implements NatsKeyValueInterface
{
    public function __construct(
        private Bucket $bucket,
    ) {
    }

    public function get(string $key): ?string
    {
        return static::retryConnection(fn() => $this->bucket->get($key));
    }

    public function getEntry(string $key): ?Entry
    {
        return static::retryConnection(fn() => $this->bucket->getEntry($key));
    }

    public function getAll(): array
    {
        return static::retryConnection(fn() => $this->bucket->getAll());
    }

    public function put(string $key, string $value): int
    {
        return static::retryConnection(fn() => $this->bucket->put($key, $value));
    }

    public function update(string $key, string $value, int $revision): int
    {
        return static::retryConnection(fn() => $this->bucket->update($key, $value, $revision));
    }

    public function delete(string $key): void
    {
        static::retryConnection(fn() => $this->bucket->delete($key));
    }

    public function purge(string $key): void
    {
        static::retryConnection(fn()=> $this->bucket->purge($key));
    }

    public function getStatus(): Status
    {
        return static::retryConnection(fn() => $this->bucket->getStatus());
    }

    /**
     * @param callable $fn
     * @param int $maxAttempts
     * @return mixed
     * @throws \Throwable
     */
    private static function retryConnection(callable $fn, int $maxAttempts = 5): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $lastException = $e;
                echo "Nats KV connection failed, attempt: {$attempt}/{$maxAttempts} — {$e->getMessage()}\n";

                if ($attempt < $maxAttempts) {
                    $delay = min($attempt * $attempt, 10);
                    Timer::sleep($delay);
                }
            }
        }

        throw $lastException;
    }
}
