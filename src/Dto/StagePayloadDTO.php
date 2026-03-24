<?php

declare(strict_types=1);

namespace WsFramework\Dto;

class StagePayloadDTO extends DataTransferObject
{
    public function __construct(
        public readonly string $jobId,
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
