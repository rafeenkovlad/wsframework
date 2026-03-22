<?php

declare(strict_types=1);

namespace WsFramework\Service\HelpService\CloseConnectionStrategy;

interface CloseConnectionStrategyInterface
{
    public function close(int $workerId, int $connectionId): void;
}
