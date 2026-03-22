<?php

declare(strict_types=1);

namespace WsFramework\Dto;

class S3DownloadDataDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?string $jobId,
        public readonly ?string $s3Key,
        public readonly ?string $s3Bucket,
        public readonly ?string $outputS3Prefix,
        public readonly ?int    $maxRetries,
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
            's3Key' => null,
            's3Bucket' => $_ENV['S3_BUCKET'] ?? null,
            'outputS3Prefix' => null,
            'maxRetries' => (int) ($_ENV['S3_PIPELINE_MAX_RETRIES'] ?? 3),
        ];
    }
}
