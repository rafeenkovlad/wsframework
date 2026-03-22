<?php

declare(strict_types=1);

namespace WsFramework\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ProcessVideoCallbackParamsDTO extends DataTransferObject
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly ?string $s3Key,
        public readonly ?string $s3Bucket,
        #[Assert\NotBlank]
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
            's3Key' => null,
            's3Bucket' => '',
            'outputS3Prefix' => $_ENV['S3_BUCKET'],
            'maxRetries' => $_ENV['S3_PIPELINE_MAX_RETRIES'],
        ];
    }
}
