<?php

declare(strict_types=1);

namespace WsFramework\Dto;

class S3UploadDataDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?string $jobId,
        public readonly ?string $localHlsDir,
        public readonly ?string $playlistFile,
        public readonly ?int    $segmentCount,
        public readonly ?string $s3Bucket,
        public readonly ?string $outputS3Prefix,
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
            'localHlsDir' => null,
            'playlistFile' => null,
            'segmentCount' => null,
            's3Bucket' => $_ENV['S3_BUCKET'] ?? null,
            'outputS3Prefix' => null,
        ];
    }
}
