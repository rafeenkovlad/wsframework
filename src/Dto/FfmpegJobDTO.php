<?php

declare(strict_types=1);

namespace WsFramework\Dto;

class FfmpegJobDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?string $jobId,
        public readonly ?string $status,
        public readonly ?string $inputFile,
        public readonly ?string $outputFile,
        public readonly ?array  $options,
        public readonly ?string $errorMessage,
        public readonly ?int    $progress,
        public readonly ?int    $priority,
        public readonly ?int    $retryCount,
        public readonly ?int    $maxRetries,
        public readonly ?string $nextRetryAt,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?string $s3Bucket,
        public readonly ?string $outputS3Prefix,
        public readonly ?string $localHlsDir,
        public readonly ?string $playlistFile,
        public readonly ?int    $segmentCount,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $error,
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
            's3Bucket' => null,
            'outputS3Prefix' => null,
            'localHlsDir' => null,
            'playlistFile' => null,
            'segmentCount' => null,
            'startedAt' => null,
            'finishedAt' => null,
            'error' => null,
        ];
    }
}
