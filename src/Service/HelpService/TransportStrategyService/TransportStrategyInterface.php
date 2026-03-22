<?php

declare(strict_types=1);

namespace WsFramework\Service\HelpService\TransportStrategyService;

interface TransportStrategyInterface
{
    /**
     * @param ?callable $isCondition
     * @return callable
     */
    public function onMessage(
        ?callable $isCondition,
    ): callable;

    /**
     * @return void
     */
    public function boot(): void;
}
