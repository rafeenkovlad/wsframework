<?php

declare(strict_types=1);

namespace WsFramework\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ProcessBrowserlessCallbackParamsDTO extends DataTransferObject
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly ?string $url,
        #[Assert\NotBlank]
        public readonly ?string $outputS3Prefix,
        public readonly ?string $s3Bucket,
        public readonly ?int    $maxRetries,
        public readonly ?string $format,
        public readonly ?int    $viewportWidth,
        public readonly ?int    $viewportHeight,
        public readonly ?string $proxy,
        public readonly ?string $proxyUsername,
        public readonly ?string $proxyPassword,
        public readonly ?string $fingerprint,
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
            'url' => null,
            'outputS3Prefix' => null,
            's3Bucket' => $_ENV['S3_BUCKET'],
            'maxRetries' => $_ENV['S3_PIPELINE_MAX_RETRIES'],
            'format' => 'screenshot',
            'viewportWidth' => 1920,
            'viewportHeight' => 1080,
            'proxy' => null,
            'proxyUsername' => null,
            'proxyPassword' => null,
            'fingerprint' => null,
        ];
    }
}
