<?php

declare(strict_types=1);

namespace WsFramework\Action\Method;

use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;
use WsFramework\Pool\Http\PoolHttpConnection;

class Healthcheck extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'Healthcheck';
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
        return true;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
        $errors = null;
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        return ['available' => true];
    }

    protected static function getDescription(): string
    {
        return 'Проверить доступность сервиса.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['available'],
            'additionalProperties' => false,
            'properties' => [
                'available' => [
                    'type' => 'boolean',
                    'description' => 'true — сервис доступен.',
                ],
            ],
        ];
    }
}
