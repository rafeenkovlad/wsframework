<?php

namespace WsFramework\Dto\UseCase;

use Throwable;
use WsFramework\Dto\DataTransferObject;
use WsFramework\Enum\JobType;

class KVMergeOptionsDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?Throwable $throwable,
        public readonly ?JobType   $jobType,
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

    protected static function getDefaultValues(): array
    {
        return [
            'throwable' => null,
            'jobType' => null,
        ];
    }
}
