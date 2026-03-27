<?php

declare(strict_types=1);

namespace WsFramework\Dto;

use WsFramework\Channel\ChannelAbstract;
use WsFramework\Enum\Pipeline;

/**
 * @property Pipeline $pipeline
 * @property ChannelAbstract $channel
 */
#[\AllowDynamicProperties]
class defaultDTO extends DataTransferObject
{
    public function __construct(
    )
    {
    }

    #[\Override] protected static function dependedDTO(): array
    {
        return [];
    }

    #[\Override] protected static function dependedCollectionDTO(): array
    {
        return [];
    }
}
