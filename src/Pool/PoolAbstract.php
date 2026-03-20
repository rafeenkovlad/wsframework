<?php

namespace WsFramework\Pool;

use WsFramework\Dto\DataTransferObject;

/**
 * Пулы принадлежат процессам, поэтому в другом процессе пул может быть пустым.
 *
 * @method $this getOffset(mixed $offset)
 */
abstract class PoolAbstract
{
    /**
     * @var array<string,Collection>
     */
    private static array $pool;

    /**
     * @return void
     */
    private static function createCollection(): void
    {
        static::$pool[static::getCollectionName()] ??= new Collection();
    }

    /**
     * @return Collection
     */
    public static function getPool(): Collection
    {
        return static::$pool[static::getCollectionName()];
    }

    /**
     * @return string
     */
    abstract public static function getCollectionName(): string;

    /**
     * Возвращает новый объект DTO
     * @param int|string $offset
     */
    protected static function addOffset(int|string $offset = 0): void
    {
        static::createCollection();
        static::$pool[static::getCollectionName()]->offsetSet($offset, static::createDTO());
    }

    /**
     * @return bool
     */
    private static function isNotExistsCollectionName(): bool
    {
        return !isset(static::$pool[static::getCollectionName()]);
    }

    /**
     * @return int
     */
    public static function count(): int
    {
        if (static::isNotExistsCollectionName()) {
            return 0;
        }

        return static::$pool[static::getCollectionName()]->count();
    }

    /**
     * @return $this|null
     */
    public static function current(): ?DataTransferObject
    {
        if (static::isNotExistsCollectionName()) {
            return null;
        }

        return static::$pool[static::getCollectionName()]->current();
    }

    /**
     * @return void
     */
    public static function next(): void
    {
        if (static::isNotExistsCollectionName()) {
            return;
        }

        static::$pool[static::getCollectionName()]->next();
    }

    /**
     * @return int
     */
    public static function key(): int
    {
        return static::$pool[static::getCollectionName()]->key();
    }

    /**
     * @return bool
     */
    public static function valid(): bool
    {
        if (static::isNotExistsCollectionName()) {
            return false;
        }

        return static::$pool[static::getCollectionName()]->valid();
    }

    /**
     * @return void
     */
    public static function rewind(): void
    {
        if (static::isNotExistsCollectionName()) {
            return;
        }

        static::$pool[static::getCollectionName()]->rewind();
    }

    /**
     * @param int|string $offset
     * @return $this|null
     */
    public static function getOffset(int|string $offset): ?DataTransferObject
    {
        if (static::isNotExistsCollectionName()) {
            return null;
        }

        return static::$pool[static::getCollectionName()][$offset] ?? null;
    }

    /**
     * @param int|string $offset
     * @return void
     */
    public static function unset(int|string $offset): void
    {
        if (static::isNotExistsCollectionName()) {
            return;
        }

        static::$pool[static::getCollectionName()]->offsetUnset($offset);
    }

    /**
     * @return string
     */
    abstract protected static function getClassDTO(): string;

    protected static function createDTO(): DataTransferObject
    {
        /** @var DataTransferObject $classDTO */
        $classDTO = static::getClassDTO();
        return $classDTO::createFromArray([]);
    }
}
