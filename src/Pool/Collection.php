<?php

namespace WsFramework\Pool;

use ArrayAccess;
use Iterator;

class Collection implements ArrayAccess, Iterator
{
    /**
     * @var int
     */
    private int $position = 0;

    /**
     * @var array
     */
    private array $items = [];

    /**
     * @var int[]
     */
    private array $mapKey = [];

    public function offsetExists(mixed $offset): bool
    {
        if (!isset($this->mapKey[$offset])) {
            return false;
        }

        return isset($this->items[$this->mapKey[$offset]]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$this->mapKey[$offset]] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->rewind();
        while ($this->valid()) {
            $this->next();
        }
        /** @var PoolAbstract $value */
        $this->items[$this->position] = $value;
        $this->mapKey[$offset] = $this->position;
        $this->rewind();
    }

    public function offsetUnset(mixed $offset): void
    {
        if (isset($this->mapKey[$offset])) {
            // Удаляем элемент из $items
            unset($this->items[$this->mapKey[$offset]]);
            // Удаляем ключ из $mapKey
            unset($this->mapKey[$offset]);
        }
    }

    public function count(): int
    {
        return count($this->mapKey);
    }

    public function next(): void
    {
        $this->position++;
    }

    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    /**
     * @return int
     */
    public function key(): int
    {
        return $this->position;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    /**
     * @return void
     */
    public function rewind(): void
    {
        $this->position = 0;
    }
}