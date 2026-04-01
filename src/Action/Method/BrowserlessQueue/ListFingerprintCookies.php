<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\BrowserlessQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Service\Browserless\CookieStorageProvider;

class ListFingerprintCookies extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'BrowserlessQueue.ListFingerprintCookies';
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

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        $storage = new CookieStorageProvider();
        $fingerprints = $storage->listFingerprints();

        return [
            'fingerprints' => $fingerprints,
        ];
    }

    protected static function getDescription(): string
    {
        return 'Получить список всех fingerprint-профилей с сохранёнными cookies.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['fingerprints'],
            'additionalProperties' => false,
            'properties' => [
                'fingerprints' => [
                    'type' => 'array',
                    'description' => 'Список fingerprint-профилей с cookies.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Имя fingerprint-профиля.',
                            ],
                            'cookieCount' => [
                                'type' => 'integer',
                                'description' => 'Количество cookies.',
                            ],
                            'updatedAt' => [
                                'type' => 'string',
                                'format' => 'date-time',
                                'description' => 'Время последнего обновления файла cookies.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
