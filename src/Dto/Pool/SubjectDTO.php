<?php

namespace WsFramework\Dto\Pool;

use Basis\Nats\Stream\Stream;
use WsFramework\Dto\DataTransferObject;

class SubjectDTO extends DataTransferObject
{
    public function __construct(
        public ?Stream $stream,
        public ?string $subject,
    )
    {
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