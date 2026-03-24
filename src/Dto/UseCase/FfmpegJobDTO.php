<?php

declare(strict_types=1);

namespace WsFramework\Dto\UseCase;

use WsFramework\Dto\DataTransferObject;

class FfmpegJobDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?string $outputFile = null,
        public readonly ?string $localHlsDir = null,
        public readonly ?string $playlistFile = null,
        public readonly ?int    $segmentCount = null,
        public readonly ?array  $options = null,
        public readonly ?int    $progress = null,
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
