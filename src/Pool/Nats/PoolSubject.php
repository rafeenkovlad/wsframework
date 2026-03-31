<?php

namespace WsFramework\Pool\Nats;

use WsFramework\Dto\Pool\SubjectDTO;
use WsFramework\Pool\PoolAbstract;

class PoolSubject extends PoolAbstract
{
    /**
     * @return string
     */
    public static function getCollectionName(): string
    {
        return 'nats_subject_collection';
    }

    /**
     * @param int|string $offset
     * @return void
     */
    public static function addOffset(int|string $offset = 0): void
    {
        parent::addOffset($offset);
        echo "+1 added nats subject, total amount: " . parent::count() . " \n";
    }

    /**
     * @param int|string $offset
     * @return SubjectDTO|null
     */
    public static function getOffset(int|string $offset): ?SubjectDTO
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
            echo "-1 remove nats subject, total amount: " . parent::count() . " \n";
        }
    }

    protected static function getClassDTO(): string
    {
        return SubjectDTO::class;
    }
}