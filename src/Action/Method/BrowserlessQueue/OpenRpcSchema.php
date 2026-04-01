<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\BrowserlessQueue;

use PSX\OpenRPC\ContentDescriptor;
use WsFramework\Enum\BrowserlessJobStatus;

final class OpenRpcSchema
{
    public static function descriptor(
        string $name,
        array $schema,
        bool $required = false,
        ?string $description = null,
    ): ContentDescriptor {
        $descriptor = new ContentDescriptor();
        $descriptor->setName($name);
        $descriptor->setRequired($required);
        $descriptor->setSchema($schema);
        $descriptor->setDescription($description);

        return $descriptor;
    }

    public static function browserlessJobSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['jobId'],
            'additionalProperties' => false,
            'properties' => [
                'jobId' => [
                    'type' => 'string',
                    'description' => 'Уникальный идентификатор задачи.',
                ],
                'status' => static::statusSchema('Текущий статус задачи в пайплайне.'),
                'outputS3Prefix' => [
                    'type' => 'string',
                    'description' => 'Префикс для выходных файлов в S3.',
                ],
                's3Bucket' => [
                    'type' => 'string',
                    'description' => 'Имя S3-бакета.',
                ],
                'retryCount' => [
                    'type' => 'integer',
                    'description' => 'Текущее количество выполненных повторных попыток.',
                ],
                'maxRetries' => [
                    'type' => 'integer',
                    'description' => 'Максимальное количество повторных попыток.',
                ],
                'priority' => [
                    'type' => 'integer',
                    'description' => 'Приоритет задачи в очереди.',
                ],
                'createdAt' => static::dateTimeSchema('Время постановки задачи в очередь.'),
                'updatedAt' => static::dateTimeSchema('Время последнего обновления состояния задачи.'),
                'startedAt' => static::dateTimeSchema('Время начала обработки задачи worker-процессом.'),
                'finishedAt' => static::dateTimeSchema('Время завершения обработки задачи.'),
                'errors' => static::errorsSchema('Массив ошибок верхнего уровня задачи.'),
                'browserlessJob' => static::browserlessSubJobSchema(),
                's3Upload' => static::s3UploadSchema(),
                'cleanup' => static::cleanupSchema(),
            ],
        ];
    }

    public static function browserlessSubJobSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Данные стадии Browserless-обработки.',
            'additionalProperties' => false,
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL страницы для обработки.',
                ],
                'format' => [
                    'type' => 'string',
                    'description' => 'Формат вывода (screenshot, pdf и т.д.).',
                ],
                'viewportWidth' => [
                    'type' => 'integer',
                    'description' => 'Ширина viewport браузера.',
                ],
                'viewportHeight' => [
                    'type' => 'integer',
                    'description' => 'Высота viewport браузера.',
                ],
                'outputPath' => [
                    'type' => 'string',
                    'description' => 'Локальный путь к результату.',
                ],
                'proxy' => [
                    'type' => 'string',
                    'description' => 'URL прокси-сервера для Chrome.',
                ],
                'fingerprint' => [
                    'type' => 'string',
                    'description' => 'Имя fingerprint-профиля браузера.',
                ],
                'errors' => static::errorsSchema('Ошибки стадии Browserless-обработки.'),
            ],
        ];
    }

    public static function s3UploadSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Данные стадии S3 Upload.',
            'additionalProperties' => false,
            'properties' => [
                'playlistS3Key' => [
                    'type' => 'string',
                    'description' => 'S3-ключ загруженного файла.',
                ],
                'errors' => static::errorsSchema('Ошибки стадии S3 Upload.'),
            ],
        ];
    }

    public static function cleanupSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Данные стадии очистки временных файлов.',
            'additionalProperties' => false,
            'properties' => [
                'errors' => static::errorsSchema('Ошибки стадии очистки.'),
            ],
        ];
    }

    public static function errorsSchema(?string $description = null): array
    {
        return [
            'type' => 'array',
            'description' => $description,
            'items' => [
                'type' => 'object',
                'additionalProperties' => true,
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'description' => 'Текст ошибки.',
                    ],
                    'at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Время возникновения ошибки.',
                    ],
                ],
            ],
        ];
    }

    public static function statusSchema(?string $description = null): array
    {
        return [
            'type' => 'string',
            'enum' => array_map(
                static fn(BrowserlessJobStatus $status): string => $status->value,
                BrowserlessJobStatus::cases(),
            ),
            'description' => $description,
        ];
    }

    public static function dateTimeSchema(?string $description = null): array
    {
        return [
            'type' => 'string',
            'format' => 'date-time',
            'description' => $description,
        ];
    }

    public static function jobIdDescriptor(string $description = 'Идентификатор задачи в очереди.'): ContentDescriptor
    {
        return static::descriptor(
            'jobId',
            [
                'type' => 'string',
                'description' => $description,
            ],
            true,
            $description,
        );
    }
}
