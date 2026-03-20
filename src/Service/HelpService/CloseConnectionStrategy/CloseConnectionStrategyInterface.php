<?php

namespace WsFramework\Service\HelpService\CloseConnectionStrategy;

interface CloseConnectionStrategyInterface
{
    public function close(int $workerId, int $connectionId): void;
}
