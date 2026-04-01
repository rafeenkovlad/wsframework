<?php

declare(strict_types=1);

namespace WsFramework\Dto\Pool;

use WsFramework\Dto\DataTransferObject;

class BrowserFingerprintDTO extends DataTransferObject
{
    public function __construct(
        public ?string $userAgent = null,
        public ?string $acceptLanguage = null,
        public ?string $platform = null,
        public ?int    $viewportWidth = null,
        public ?int    $viewportHeight = null,
        public ?string $webglVendor = null,
        public ?string $webglRenderer = null,
        public ?int    $hardwareConcurrency = null,
        public ?int    $deviceMemory = null,
        public ?int    $screenWidth = null,
        public ?int    $screenHeight = null,
        public ?int    $screenAvailWidth = null,
        public ?int    $screenAvailHeight = null,
        public ?int    $screenColorDepth = null,
        public ?string $connectionType = null,
        public ?int    $connectionRtt = null,
        public ?float  $connectionDownlink = null,
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
