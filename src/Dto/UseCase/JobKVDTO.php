<?php

declare(strict_types=1);

namespace WsFramework\Dto\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Enum\JobType;

class JobKVDTO extends DataTransferObject
{
    public function __construct(
        public readonly string  $jobId,
        public readonly ?string $status = null,
        public readonly ?string $s3Key = null,
        public readonly ?string $s3Bucket = null,
        public readonly ?string $outputS3Prefix = null,
        public readonly ?int    $retryCount = null,
        public readonly ?int    $maxRetries = null,
        public readonly ?int    $priority = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?array  $errors = null,
        public readonly ?S3DownloadJobDTO $s3Download = null,
        public readonly ?FfmpegJobDTO     $ffmpegJob = null,
        public readonly ?S3UploadJobDTO   $s3Upload = null,
        public readonly ?CleanupJobDTO    $cleanup = null,
        public readonly ?BrowserlessJobDTO $browserlessJob = null,
    ) {
    }

    protected static function dependedDTO(): array
    {
        return [
            's3Download' => S3DownloadJobDTO::class,
            'ffmpegJob'  => FfmpegJobDTO::class,
            's3Upload'   => S3UploadJobDTO::class,
            'cleanup'    => CleanupJobDTO::class,
            'browserlessJob' => BrowserlessJobDTO::class,
        ];
    }

    protected static function dependedCollectionDTO(): array
    {
        return [];
    }

    protected static function getDefaultValues(): array
    {
        return ['jobId' => 'undefined'];
    }

    public function resolveJobType(): JobType
    {
        if ($this->browserlessJob !== null) {
            return JobType::BROWSERLESS;
        }

        return JobType::FFMPEG;
    }
}
