<?php

declare(strict_types=1);

namespace WsFramework\Dto;

class FfmpegJobDTO extends DataTransferObject
{
    public function __construct(
        public ?string $jobId,
        public ?string $status,
        public ?string $inputFile,
        public ?string $outputFile,
        public ?array  $options,
        public ?string $errorMessage,
        public ?int    $progress,
        public ?int    $priority,
        public ?int    $retryCount,
        public ?int    $maxRetries,
        public ?string $nextRetryAt,
        public ?string $createdAt,
        public ?string $updatedAt,
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
            'jobId' => null,
            'status' => 'pending',
            'inputFile' => null,
            'outputFile' => null,
            'options' => [],
            'errorMessage' => null,
            'progress' => 0,
            'priority' => 10,
            'retryCount' => 0,
            'maxRetries' => 3,
            'nextRetryAt' => null,
            'createdAt' => null,
            'updatedAt' => null,
        ];
    }
}
