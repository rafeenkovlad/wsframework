<?php

declare(strict_types=1);

namespace WsFramework\Service\S3;

use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use WsFramework\Exception\S3\S3DownloadSizeMismatchException;
use WsFramework\Exception\S3\S3MissingContentLengthException;
use WsFramework\UseCase\GetS3ClientUseCase;

readonly class S3ClientService
{
    private S3Client $client;

    public function __construct()
    {
        $this->client = GetS3ClientUseCase::handle();
    }

    public function download(string $bucket, string $key, string $localPath): void
    {
        $head = $this->client->headObject([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);
        $expectedSize = (int) ($head['ContentLength'] ?? throw new S3MissingContentLengthException($key));

        $this->client->getObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            '@http'  => ['sink' => $localPath],
        ]);

        clearstatcache(true, $localPath);
        $actualSize = filesize($localPath);
        if ($actualSize === false || $actualSize !== $expectedSize) {
            if (file_exists($localPath)) {
                unlink($localPath);
            }
            throw new S3DownloadSizeMismatchException($key, $expectedSize, $actualSize);
        }
    }

    public function uploadFile(string $localPath, string $bucket, string $s3Key, ?string $contentType = null): void
    {
        $body = fopen($localPath, 'rb');

        if ($body === false) {
            throw new \RuntimeException("Cannot open {$localPath} for reading");
        }

        $options = $contentType !== null ? ['ContentType' => $contentType] : [];

        $uploader = new ObjectUploader($this->client, $bucket, $s3Key, $body, 'private', $options);
        $uploader->upload();

        fclose($body);
    }
}
