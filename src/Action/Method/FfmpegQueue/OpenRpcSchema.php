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
            'required' => ['jobId', 'status'],
            'additionalProperties' => false,
            'properties' => [
                'jobId' => [
                    'type' => 'string',
                    'description' => 'Уникальный идентификатор задачи.',
                ],
                'status' => static::statusSchema('Текущий статус задачи в очереди.'),
                'inputFile' => [
                    'type' => 'string',
                    'description' => 'Относительный или абсолютный путь к исходному видео.',
                ],
                'outputFile' => [
                    'type' => 'string',
                    'description' => 'Имя/путь результата, сохранённый в метаданных задачи.',
                ],
                'options' => static::ffmpegOptionsSchema(),
                'priority' => [
                    'type' => 'integer',
                    'description' => 'Приоритет задачи, сохранённый в метаданных очереди.',
                ],
                'createdAt' => static::dateTimeSchema('Время постановки задачи в очередь.'),
                'updatedAt' => static::dateTimeSchema('Время последнего обновления состояния задачи.'),
                'startedAt' => static::dateTimeSchema('Время начала обработки задачи worker-процессом.'),
                'finishedAt' => static::dateTimeSchema('Время завершения обработки задачи.'),
                'error' => [
                    'type' => 'string',
                    'description' => 'Короткое сообщение об ошибке.',
                ],
                'errorMessage' => [
                    'type' => 'string',
                    'description' => 'Текст ошибки в человекочитаемом виде.',
                ],
            ],
        ];
    }

    public static function ffmpegOptionsSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Произвольные параметры FFmpeg, сохраняемые в payload/метаданных задачи.',
            'additionalProperties' => true,
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
