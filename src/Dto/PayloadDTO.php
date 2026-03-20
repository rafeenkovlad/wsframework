<?php

namespace WsFramework\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class PayloadDTO extends DataTransferObject
{
    public function __construct(
        public ?string $token,
        public ?int $connectionId,
        public ?int $workerId,
        public ?int $userId,
        public ?string $httpMethod,
        public ?string $path,
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