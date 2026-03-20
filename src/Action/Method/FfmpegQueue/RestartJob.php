<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;

class RestartJob extends MethodAbstract
{

    public static function getMethodName(): string
    {
        return 'ffmpegQueue.restartJob';
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
        // TODO: реализовать перезапуск задачи
        return [];
    }

    protected static function getDescription(): string
    {
        return 'Restart a failed FFmpeg queue job';
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
                'restarted' => ['type' => 'boolean'],
            ],
        ];
    }
}
