<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use Aws\S3\S3Client;
use GuzzleHttp\HandlerStack;
use Hyperf\Guzzle\CoroutineHandler;
use WsFramework\Dto\DataTransferObject;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\S3\S3MissingConfigException;

class GetS3ClientUseCase extends AbstractUseCase
{
    private S3Client $s3Client;

    public static function handle(?DataTransferObject $DTO = null, ...$args): S3Client
    {
        return static::create()->execute()->getClient();
    }

    /**
     * @return $this
     * @throws PipelineException
     * @throws S3MissingConfigException
     */
    private function execute(): static
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
            'calculate_md5' => true,
        ];

        $endpoint = $_ENV['S3_ENDPOINT'] ?? throw new PipelineException(
            DefineCurrentPipelineUseCase::handle()->getName(),
            'Endpoint for S3 is not defined.'
        );
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        $this->s3Client ??= new S3Client($config);

        return $this;
    }

    private function getClient(): S3Client
    {
        return $this->s3Client;
    }
}
