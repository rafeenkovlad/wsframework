<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;

class GetJobStatus extends MethodAbstract
{

    public static function getMethodName(): string
    {
        return 'ffmpegQueue.getJobStatus';
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
        // TODO: реализовать получение статуса задачи
        return [];
    }

    protected static function getDescription(): string
    {
        return 'Get status of an FFmpeg queue job';
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
                'progress' => ['type' => 'integer'],
                'errorMessage' => ['type' => 'string'],
            ],
        ];
    }
}
