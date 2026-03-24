<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use PSX\OpenRPC\ContentDescriptor;
use WsFramework\Enum\FfmpegJobStatus;

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

    public static function ffmpegJobSchema(): array
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
                's3Key' => [
                    'type' => 'string',
                    'description' => 'Ключ исходного файла в S3-бакете.',
                ],
                's3Bucket' => [
                    'type' => 'string',
                    'description' => 'Имя S3-бакета.',
                ],
                'outputS3Prefix' => [
                    'type' => 'string',
                    'description' => 'Префикс для выходных HLS-файлов в S3.',
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
                's3Download' => static::s3DownloadSchema(),
                'ffmpegJob' => static::ffmpegSubJobSchema(),
                's3Upload' => static::s3UploadSchema(),
                'cleanup' => static::cleanupSchema(),
            ],
        ];
    }

    public static function s3DownloadSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Данные стадии S3 Download.',
            'additionalProperties' => false,
            'properties' => [
                'inputFile' => [
                    'type' => 'string',
                    'description' => 'Локальный путь к скачанному файлу.',
                ],
                'errors' => static::errorsSchema('Ошибки стадии S3 Download.'),
            ],
        ];
    }

    public static function ffmpegSubJobSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Данные стадии FFmpeg-конвертации.',
            'additionalProperties' => false,
            'properties' => [
                'outputFile' => [
                    'type' => 'string',
                    'description' => 'Имя/путь результата конвертации.',
                ],
                'localHlsDir' => [
                    'type' => 'string',
                    'description' => 'Локальная директория с HLS-сегментами.',
                ],
                'playlistFile' => [
                    'type' => 'string',
                    'description' => 'Путь к master playlist (.m3u8).',
                ],
                'segmentCount' => [
                    'type' => 'integer',
                    'description' => 'Количество HLS-сегментов.',
                ],
                'options' => [
                    'type' => 'object',
                    'description' => 'Произвольные параметры FFmpeg.',
                    'additionalProperties' => true,
                ],
                'progress' => [
                    'type' => 'integer',
                    'description' => 'Прогресс конвертации (0–100).',
                ],
                'errors' => static::errorsSchema('Ошибки стадии FFmpeg-конвертации.'),
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
                    'description' => 'S3-ключ загруженного master playlist.',
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
                static fn(FfmpegJobStatus $status): string => $status->value,
                FfmpegJobStatus::cases(),
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
