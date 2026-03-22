<?php

declare(strict_types=1);

namespace Package\NatsClient;

use Basis\Nats\KeyValue\Entry;
use Basis\Nats\KeyValue\Status;

interface NatsKeyValueInterface
{
    /**
     * Получить значение по ключу.
     */
    public function get(string $key): ?string;

    /**
     * Получить Entry (значение + revision + метаданные).
     */
    public function getEntry(string $key): ?Entry;

    /**
     * Получить все пары ключ-значение.
     *
     * @return Entry[]
     */
    public function getAll(): array;

    /**
     * Записать значение по ключу. Возвращает revision.
     */
    public function put(string $key, string $value): int;

    /**
     * Обновить значение с проверкой revision (optimistic lock).
     */
    public function update(string $key, string $value, int $revision): int;

    /**
     * Удалить значение по ключу.
     */
    public function delete(string $key): void;

    /**
     * Очистить историю значений по ключу.
     */
    public function purge(string $key): void;

    /**
     * Получить статус bucket'а.
     */
    public function getStatus(): Status;
}
