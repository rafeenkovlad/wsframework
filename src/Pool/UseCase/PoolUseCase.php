<?php

namespace WsFramework\Pool\UseCase;

use WsFramework\Dto\Pool\UseCaseDTO;
use WsFramework\Pool\PoolAbstract;

class PoolUseCase extends PoolAbstract
{

    public static function getCollectionName(): string
    {
        return 'use_case_collection';
    }

    protected static function getClassDTO(): string
    {
        return UseCaseDTO::class;
    }

    public static function getOffset(int|string $offset): ?UseCaseDTO
    {
        return parent::getOffset($offset);
    }

    public static function addOffset(int|string $offset = 0): void
    {
        if (static::getOffset($offset)) {
            static::unset($offset);
        }

        parent::addOffset($offset);
        echo "+1 register use case {$offset}, total amount: " . parent::count() . " \n";

    }
}