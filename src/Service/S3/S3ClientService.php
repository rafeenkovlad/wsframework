<?php

declare(strict_types=1);

namespace WsFramework\Service\S3;

use Aws\S3\S3Client;
use GuzzleHttp\HandlerStack;
use Hyperf\Guzzle\CoroutineHandler;
use WsFramework\Exception\S3\S3DownloadSizeMismatchException;
use WsFramework\Exception\S3\S3MissingConfigException;
use WsFramework\Exception\S3\S3MissingContentLengthException;

class S3ClientService
{
    private readonly S3Client $client;

    public function __construct()
    {
        $config = [
            'region' => $_ENV['S3_REGION'] ?? throw new S3MissingConfigException('S3_REGION'),
            'version' => 'latest',
            'credentials' => [
                'key' => $_ENV['S3_ACCESS_KEY'] ?? throw new S3MissingConfigException('S3_ACCESS_KEY'),
                'secret' => $_ENV['S3_SECRET_KEY'] ?? throw new S3MissingConfigException('S3_SECRET_KEY'),
            ],
            'http' => [
                'timeout' => (int) ($_ENV['S3_HTTP_TIMEOUT'] ?? 600),
                'connect_timeout' => (int) ($_ENV['S3_HTTP_CONNECT_TIMEOUT'] ?? 10),
            ],
            'http_handler' => HandlerStack::create(new CoroutineHandler()),
        ];

        $endpoint = $_ENV['S3_ENDPOINT'] ?? '';
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        $this->client = new S3Client($config);
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
            'SaveAs' => $localPath,
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
        $params = [
            'Bucket' => $bucket,
            'Key' => $s3Key,
            'SourceFile' => $localPath,
        ];

        if ($contentType !== null) {
            $params['ContentType'] = $contentType;
        }

        $this->client->putObject($params);
    }
}
