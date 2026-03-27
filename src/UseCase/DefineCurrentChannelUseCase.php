<?php

namespace WsFramework\UseCase;

use WsFramework\Channel\ChannelAbstract;
use WsFramework\Channel\SelectEventInterface;
use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\defaultDTO;

class DefineCurrentChannelUseCase extends AbstractUseCase
{
    private ChannelAbstract $channel;

    public static function handle(?DataTransferObject $DTO = null, ...$args): SelectEventInterface
    {
        return static::create($DTO)->setChannel()->execute();
    }

    private function setChannel(): static
    {
        /** @var DefaultDTO $DTO */
        $DTO = $this->DTO;
        $this->channel ??= $DTO?->channel;

        return $this;
    }

    private function execute(): SelectEventInterface
    {
        return $this->channel->eventInterface();
    }
}