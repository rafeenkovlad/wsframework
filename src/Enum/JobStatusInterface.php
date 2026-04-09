<?php

declare(strict_types=1);

namespace WsFramework\Enum;

interface JobStatusInterface
{
    public function pipeline(): Pipeline;

    public function isRestartable(): bool;

    public function isTerminal(): bool;

    public function getValue(): mixed;
}
