<?php

declare(strict_types=1);

namespace WsFramework\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use WsFramework\Enum\BrowserlessJobStatus;

class BrowserlessListJobsParamsDTO extends DataTransferObject
{
    public function __construct(
        #[Assert\Choice(callback: [BrowserlessJobStatus::class, 'getValues'], message: 'Invalid status filter')]
        public readonly ?string $status,
        #[Assert\Range(min: 1, max: 200)]
        public readonly ?int $limit,
        #[Assert\PositiveOrZero]
        public readonly ?int $offset,
        #[Assert\Choice(choices: ['asc', 'desc'])]
        public readonly ?string $sortOrder,
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

    protected static function getDefaultValues(): array
    {
        return [
            'status' => null,
            'limit' => 50,
            'offset' => 0,
            'sortOrder' => 'desc',
        ];
    }
}
