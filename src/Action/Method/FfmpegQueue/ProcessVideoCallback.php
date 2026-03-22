<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\ProcessVideoCallbackParamsDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Middleware\MethodParamsToDTO;
use WsFramework\Pool\Http\PoolHttpConnection;
use Symfony\Component\Validator\Validation;

class ProcessVideoCallback extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'ffmpegQueue.processVideoCallback';
    }

    public static function getResponseClass(): string
    {
        return Ok::class;
    }

    public static function getChannelClass(): ?string
    {
        return null;
    }

    public static function getPoolConnectionClass(): ?string
    {
        return PoolHttpConnection::class;
    }

    public static function isDisabledResponse(): bool
    {
        return false;
    }

    protected static function middleware(int $workerId, int $connectionId, MethodDTO $methodDTO): void
    {
        MethodParamsToDTO::main($methodDTO, ProcessVideoCallbackParamsDTO::class);
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
        /** @var ProcessVideoCallbackParamsDTO $params */
        $params = $methodDTO->params;

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $errors = $validator->validate($params);
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        /** @var ProcessVideoCallbackParamsDTO $params */
        $params = $methodDTO->params;
        $jobId = bin2hex(random_bytes(16));

        try {
            static::setKVJobStatus($jobId, FfmpegJobStatus::S3_DOWNLOAD_PENDING, $params);
            S3NatsChannel::eventInterface()->publish(
                json_encode([
                    'jobId' => $jobId,
                    's3Key' => $params->s3Key,
                    's3Bucket' => $params->s3Bucket,
                    'outputS3Prefix' => $params->outputS3Prefix,
                    'maxRetries' => $params->maxRetries,
                ], JSON_THROW_ON_ERROR),
                S3NatsChannel::METHOD_DOWNLOAD,
            );
        } catch (\Throwable $e) {

            static::setKVJobStatus(
                $jobId,
                FfmpegJobStatus::S3_DOWNLOAD_FAILED,
                $params,
                $e->getMessage()
            );

            return ['jobId' => $jobId, 'status' => FfmpegJobStatus::S3_DOWNLOAD_FAILED->value];
        }

        return ['jobId' => $jobId, 'status' => FfmpegJobStatus::S3_DOWNLOAD_PENDING->value];
    }

    /**
     * @param string $jobId
     * @param FfmpegJobStatus $status
     * @param ProcessVideoCallbackParamsDTO $params
     * @param $error
     * @return void
     * @throws \JsonException
     */
    private static function setKVJobStatus(
        string $jobId,
        FfmpegJobStatus $status,
        ProcessVideoCallbackParamsDTO $params,
        $error = null
    ): void
    {
        $kv = S3NatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
        $timestamp = date('c');
        $kv->put($jobId, json_encode([
            'jobId' => $jobId,
            'status' => $status->value,
            's3Key' => $params->s3Key,
            's3Bucket' => $params->s3Bucket,
            'outputS3Prefix' => $params->outputS3Prefix,
            'maxRetries' => $params->maxRetries,
            'retryCount' => 0,
            'createdAt' => $timestamp,
            'updatedAt' => $timestamp,
            'lastError' => $error,
        ], JSON_THROW_ON_ERROR));
    }

    protected static function getDescription(): string
    {
        return 'Запустить полный пайплайн: S3 Download → FFmpeg HLS → S3 Upload.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            OpenRpcSchema::descriptor(
                's3Key',
                [
                    'type' => 'string',
                    'description' => 'Ключ файла в S3-бакете.',
                ],
                true,
                'Обязательный ключ файла в S3.',
            ),
            OpenRpcSchema::descriptor(
                's3Bucket',
                [
                    'type' => 'string',
                    'description' => 'Имя S3-бакета. По умолчанию: $_ENV[S3_BUCKET].',
                ],
                false,
                'Опциональное имя бакета.',
            ),
            OpenRpcSchema::descriptor(
                'outputS3Prefix',
                [
                    'type' => 'string',
                    'description' => 'Префикс для выходных HLS-файлов в S3. По умолчанию: dirname(s3Key)/hls/.',
                ],
                false,
                'Опциональный S3-префикс результата.',
            ),
            OpenRpcSchema::descriptor(
                'maxRetries',
                [
                    'type' => 'integer',
                    'description' => 'Максимальное количество повторных попыток для S3 операций.',
                ],
                false,
                'Максимальное число ретраев.',
            ),
        ];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['jobId', 'status'],
            'additionalProperties' => false,
            'properties' => [
                'jobId' => [
                    'type' => 'string',
                    'description' => 'Идентификатор созданной задачи.',
                ],
                'status' => OpenRpcSchema::statusSchema('Стартовый статус задачи после постановки в очередь.'),
            ],
        ];
    }
}
