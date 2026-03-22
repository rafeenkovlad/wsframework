<?php

declare(strict_types=1);

namespace WsFramework\Dto\Pool;

use WsFramework\Dto\DataTransferObject;
use Workerman\Connection\TcpConnection;

class HttpConnectionDTO extends DataTransferObject
{
    public function __construct(
        public ?TcpConnection $connection,
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
