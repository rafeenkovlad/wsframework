<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\BrowserlessQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\ProcessBrowserlessCallbackParamsDTO;
use WsFramework\Dto\UseCase\BrowserlessJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\KVMergeOptionsDTO;
use WsFramework\Enum\BrowserlessJobStatus;
use WsFramework\Enum\JobType;
use WsFramework\Middleware\ApiKeyAuth;
use WsFramework\Middleware\MethodParamsToDTO;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\JobKVMergeUseCase;
use Symfony\Component\Validator\Validation;

class ProcessBrowserlessCallback extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'BrowserlessQueue.ProcessBrowserlessCallback';
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

    protected static function middleware(int $workerId, int $connectionId, MethodDTO $methodDTO): bool
    {
        if (!ApiKeyAuth::check($methodDTO)) {
            return false;
        }
        MethodParamsToDTO::main($methodDTO, ProcessBrowserlessCallbackParamsDTO::class);
        return true;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
        /** @var ProcessBrowserlessCallbackParamsDTO $params */
        $params = $methodDTO->params;

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $errors = $validator->validate($params);
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        /** @var ProcessBrowserlessCallbackParamsDTO $params */
        $params = $methodDTO->params;
        $jobId = bin2hex(random_bytes(16));

        try {
            static::setKVJobStatus($jobId, BrowserlessJobStatus::BROWSERLESS_PENDING, $params);

            DispatchJobByStatusUseCase::handle(
                JobKVDTO::createFromArray(['jobId' => $jobId, 'status' => BrowserlessJobStatus::BROWSERLESS_PENDING->value])
            );

            $status = BrowserlessJobStatus::BROWSERLESS_PENDING->value;

        } catch (\Throwable $e) {

            static::setKVJobStatus(
                $jobId,
                BrowserlessJobStatus::BROWSERLESS_FAILED,
                $params,
                $e->getMessage()
            );

            $status = BrowserlessJobStatus::BROWSERLESS_FAILED->value;
        }

        return ['jobId' => $jobId, 'status' => $status];
    }

    private static function setKVJobStatus(
        string $jobId,
        BrowserlessJobStatus $status,
        ProcessBrowserlessCallbackParamsDTO $params,
        $error = null
    ): void
    {
        $timestamp = date('c');
        $job = new JobKVDTO(
            jobId: $jobId,
            status: $status->value,
            s3Bucket: $params->s3Bucket,
            outputS3Prefix: $params->outputS3Prefix,
            retryCount: 0,
            maxRetries: $params->maxRetries,
            createdAt: $timestamp,
            errors: $error !== null ? [['message' => $error, 'at' => $timestamp]] : null,
            browserlessJob: new BrowserlessJobDTO(
                url: $params->url,
                format: $params->format,
                viewportWidth: $params->viewportWidth,
                viewportHeight: $params->viewportHeight,
                proxy: $params->proxy,
                proxyUsername: $params->proxyUsername,
                proxyPassword: $params->proxyPassword,
                fingerprint: $params->fingerprint,
            ),
        );

        JobKVMergeUseCase::handle($job, KVMergeOptionsDTO::createFromArray(['jobType' => JobType::BROWSERLESS]));
    }

    protected static function getDescription(): string
    {
        return 'Запустить пайплайн: Browserless → S3 Upload.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            OpenRpcSchema::descriptor(
                'url',
                [
                    'type' => 'string',
                    'description' => 'URL страницы для обработки.',
                ],
                true,
                'Обязательный URL страницы.',
            ),
            OpenRpcSchema::descriptor(
                'outputS3Prefix',
                [
                    'type' => 'string',
                    'description' => 'Префикс для выходных файлов в S3.',
                ],
                true,
                'Обязательный S3-префикс результата.',
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
                'maxRetries',
                [
                    'type' => 'integer',
                    'description' => 'Максимальное количество повторных попыток для S3 операций.',
                ],
                false,
                'Максимальное число ретраев.',
            ),
            OpenRpcSchema::descriptor(
                'format',
                [
                    'type' => 'string',
                    'description' => 'Формат вывода (screenshot, pdf). По умолчанию: screenshot.',
                ],
                false,
                'Формат вывода.',
            ),
            OpenRpcSchema::descriptor(
                'viewportWidth',
                [
                    'type' => 'integer',
                    'description' => 'Ширина viewport браузера. По умолчанию: 1920.',
                ],
                false,
                'Ширина viewport.',
            ),
            OpenRpcSchema::descriptor(
                'viewportHeight',
                [
                    'type' => 'integer',
                    'description' => 'Высота viewport браузера. По умолчанию: 1080.',
                ],
                false,
                'Высота viewport.',
            ),
            OpenRpcSchema::descriptor(
                'proxy',
                [
                    'type' => 'string',
                    'description' => 'URL прокси-сервера (http://host:port). Переопределяет BROWSERLESS_PROXY_URL.',
                ],
                false,
                'Опциональный прокси для Chrome.',
            ),
            OpenRpcSchema::descriptor(
                'proxyUsername',
                [
                    'type' => 'string',
                    'description' => 'Логин прокси. Переопределяет BROWSERLESS_PROXY_USERNAME.',
                ],
                false,
                'Опциональный логин прокси.',
            ),
            OpenRpcSchema::descriptor(
                'proxyPassword',
                [
                    'type' => 'string',
                    'description' => 'Пароль прокси. Переопределяет BROWSERLESS_PROXY_PASSWORD.',
                ],
                false,
                'Опциональный пароль прокси.',
            ),
            OpenRpcSchema::descriptor(
                'fingerprint',
                [
                    'type' => 'string',
                    'description' => 'Имя fingerprint-профиля браузера. Если не указан — выбирается случайно.',
                ],
                false,
                'Опциональный fingerprint-профиль.',
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
