<?php

declare(strict_types=1);

namespace WsFramework\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class PayloadDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?string $token,
        public readonly ?int $connectionId,
        public readonly ?int $workerId,
        public readonly ?int $userId,
        public readonly ?string $httpMethod,
        public readonly ?string $path,
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