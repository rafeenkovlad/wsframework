<?php

declare(strict_types=1);

namespace WsFramework\Dto\Pool;

use WsFramework\Dto\DataTransferObject;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Queue;

class ConsumerDTO extends DataTransferObject
{
    public function __construct(
        public ?Consumer $consumer,
        public ?Queue $queue,
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
