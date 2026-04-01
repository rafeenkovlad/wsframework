<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\BrowserlessQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Service\Browserless\CookieStorageProvider;

class GetFingerprintCookies extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'BrowserlessQueue.GetFingerprintCookies';
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
        $params = is_array($methodDTO->params) ? $methodDTO->params : [];
        $fingerprint = $params['fingerprint'] ?? null;

        if (!is_string($fingerprint) || $fingerprint === '') {
            $methodDTO->response->errors = [['field' => 'fingerprint', 'message' => 'fingerprint is required']];
            return [];
        }

        $storage = new CookieStorageProvider();
        $cookies = $storage->getCookies($fingerprint);

        if ($cookies === null) {
            $methodDTO->response->errors = [['field' => 'fingerprint', 'message' => 'No cookies found for fingerprint: ' . $fingerprint]];
            return [];
        }

        return [
            'fingerprint' => $fingerprint,
            'cookieCount' => count($cookies),
            'cookies' => $cookies,
        ];
    }

    protected static function getDescription(): string
    {
        return 'Получить сохранённые cookies для указанного fingerprint-профиля.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            OpenRpcSchema::descriptor(
                'fingerprint',
                [
                    'type' => 'string',
                    'description' => 'Имя fingerprint-профиля.',
                ],
                true,
                'Имя fingerprint-профиля.',
            ),
        ];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['fingerprint', 'cookieCount', 'cookies'],
            'additionalProperties' => false,
            'properties' => [
                'fingerprint' => [
                    'type' => 'string',
                    'description' => 'Имя fingerprint-профиля.',
                ],
                'cookieCount' => [
                    'type' => 'integer',
                    'description' => 'Количество сохранённых cookies.',
                ],
                'cookies' => [
                    'type' => 'array',
                    'description' => 'Массив cookie-объектов.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
        ];
    }
}
