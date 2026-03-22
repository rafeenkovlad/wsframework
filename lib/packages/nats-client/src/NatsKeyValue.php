<?php

declare(strict_types=1);

namespace Package\NatsClient;

use Basis\Nats\KeyValue\Bucket;
use Basis\Nats\KeyValue\Entry;
use Basis\Nats\KeyValue\Status;

class NatsKeyValue implements NatsKeyValueInterface
{
    public function __construct(
        private readonly Bucket $bucket,
    ) {
    }

    public function get(string $key): ?string
    {
        return $this->bucket->get($key);
    }

    public function getEntry(string $key): ?Entry
    {
        return $this->bucket->getEntry($key);
    }

    public function getAll(): array
    {
        return $this->bucket->getAll();
    }

    public function put(string $key, string $value): int
    {
        return $this->bucket->put($key, $value);
    }

    public function update(string $key, string $value, int $revision): int
    {
        return $this->bucket->update($key, $value, $revision);
    }

    public function delete(string $key): void
    {
        $this->bucket->delete($key);
    }

    public function purge(string $key): void
    {
        $this->bucket->purge($key);
    }

    public function getStatus(): Status
    {
        return $this->bucket->getStatus();
    }
}
