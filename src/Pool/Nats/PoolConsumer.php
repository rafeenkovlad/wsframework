<?php

declare(strict_types=1);

namespace WsFramework\Pool\Nats;

use WsFramework\Dto\Pool\ConsumerDTO;
use WsFramework\Pool\PoolAbstract;

class PoolConsumer extends PoolAbstract
{
    /**
     * @return string
     */
    public static function getCollectionName(): string
    {
        return 'nats_consumer_collection';
    }

    /**
     * @param int|string $offset
     * @return void
     */
    public static function addOffset(int|string $offset = 0): void
    {
        parent::addOffset($offset);
        echo "+1 added nats consumer, total amount: " . parent::count() . " \n";
    }

    /**
     * @param int|string $offset
     * @return ConsumerDTO|null
     */
    public static function getOffset(int|string $offset): ?ConsumerDTO
    {
        return parent::getOffset($offset);
    }

    /**
     * @param int|string $offset
     * @return void
     */
    public static function unset(int|string $offset): void
    {
        if (is_a(parent::getOffset($offset), static::getClassDTO())) {
            parent::unset($offset);
            echo "-1 remove nats consumer, total amount: " . parent::count() . " \n";
        }
    }

    protected static function getClassDTO(): string
    {
        return ConsumerDTO::class;
    }
}
