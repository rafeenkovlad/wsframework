<?php

declare(strict_types=1);

namespace WsFramework\Dto\UseCase;

use WsFramework\Dto\DataTransferObject;

class S3DownloadJobDTO extends DataTransferObject
{
    public function __construct(
        public readonly ?string $inputFile = null,
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
