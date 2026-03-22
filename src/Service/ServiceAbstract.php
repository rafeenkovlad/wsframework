<?php

declare(strict_types=1);

namespace WsFramework\Service;

use WsFramework\Service\HelpService\TransportStrategyService\TransportStrategyInterface;

abstract class ServiceAbstract
{
    public function __construct(
        protected ?TransportStrategyInterface $transport = null,
    ) {
        $this->transport?->boot();
    }


    /**
     * @return callable|null
     */
    public function onMessage(): ?callable
    {
        return $this->transport?->onMessage(
            static fn($connection, $methodDTO): bool => static::isCondition($connection, $methodDTO),
        );
    }

    /**
     * @return callable
     */
    abstract public function onWorkerStart(): callable;

    /**
     * Функция beautify json
     * @param string $data
     * @return void
     */
    protected static function jsonBeautify(string &$data): void
    {
        $data = preg_replace('/^\"(.*)?\"$/suix', "$1",
            stripslashes(
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ),
        );
    }

    abstract protected static function namespaceAction(): string;
}
