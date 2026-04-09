<?php

declare(strict_types=1);

namespace WsFramework\Middleware;

use WsFramework\Dto\MethodDTO;

final class ApiKeyAuth
{
    private const HEADER_NAME = 'x-api-key';

    public static function check(MethodDTO $methodDTO): bool
    {
        $requiredKey = $_ENV['HTTP_API_KEY'] ?? '';

        if ($requiredKey === '') {
            return true;
        }

        $providedKey = $methodDTO->headers[self::HEADER_NAME] ?? '';

        return hash_equals($requiredKey, $providedKey);
    }
}
