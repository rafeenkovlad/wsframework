<?php

declare(strict_types=1);

namespace WsFramework\Dto\UseCase;

use WsFramework\Dto\DataTransferObject;

class BrowserlessJobDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $format = null,
        public readonly ?int    $viewportWidth = null,
        public readonly ?int    $viewportHeight = null,
        public readonly ?string $outputPath = null,
        public readonly ?string $proxy = null,
        public readonly ?string $proxyUsername = null,
        public readonly ?string $proxyPassword = null,
        public readonly ?string $fingerprint = null,
        public readonly ?array  $errors = null,
    ) {
    }

    protected static function dependedDTO(): array
    {
        return [];
    }

    protected static function dependedCollectionDTO(): array
    {
        return [];
    }
}
