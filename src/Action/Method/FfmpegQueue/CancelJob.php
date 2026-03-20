<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;

class CancelJob extends MethodAbstract
{

    public static function getMethodName(): string
    {
        return 'ffmpegQueue.cancelJob';
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
        return null;
    }

    public static function isDisabledResponse(): bool
    {
        return false;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        // TODO: реализовать отмену задачи
        return [];
    }

    protected static function getDescription(): string
    {
        return 'Cancel a pending FFmpeg queue job';
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
                'cancelled' => ['type' => 'boolean'],
            ],
        ];
    }
}
