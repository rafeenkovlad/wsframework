<?php

declare(strict_types=1);

namespace WsFramework\Dto\UseCase;

use WsFramework\Dto\DataTransferObject;

class CleanupJobDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?array $errors = null,
    ) {
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
