<?php

namespace WsFramework\Dto\Pool;

use WsFramework\Dto\DataTransferObject;
use WsFramework\UseCase\AbstractUseCase;

class UseCaseDTO extends DataTransferObject
{
    public function __construct(
        public ?AbstractUseCase $useCase,
    )
    {
    }

    protected static function dependedDTO(): array
    {
        return [];
    }

    protected static function dependedCollectionDTO(): array
    {
        return [];
    }
}