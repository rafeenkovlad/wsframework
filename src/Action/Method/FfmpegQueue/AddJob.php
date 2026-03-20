<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Queue;
use WsFramework\Channel\FfmpegQueueChannel;
use WsFramework\Dto\MethodDTO;

class AddJob extends MethodAbstract
{

    public static function getMethodName(): string
    {
        return 'ffmpegQueue.addJob';
    }

    public static function getResponseClass(): string
    {
        return Queue::class;
    }

    public static function getChannelClass(): ?string
    {
        return FfmpegQueueChannel::class;
    }

    public static function getPoolConnectionClass(): ?string
    {
        return null;
    }

    public static function isDisabledResponse(): bool
    {
        return false;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
        $params = $methodDTO->params;
        $inputFile = is_array($params) ? ($params['inputFile'] ?? null) : null;
        $outputFile = is_array($params) ? ($params['outputFile'] ?? null) : null;

        if (empty($inputFile) || empty($outputFile)) {
            $errors = null;
            // Будет обработано в process() с пустым результатом
        }
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        // TODO: реализовать сохранение задачи
        return [];
    }

    protected static function getDescription(): string
    {
        return 'Add a new FFmpeg job to the processing queue';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [static::getContentDescriptor()];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jobId' => ['type' => 'string'],
                'status' => ['type' => 'string'],
            ],
        ];
    }
}
