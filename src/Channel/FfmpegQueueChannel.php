<?php

declare(strict_types=1);

namespace WsFramework\Channel;

use WsFramework\GlobalData\FfmpegQueueGlobalData;

class FfmpegQueueChannel extends ChannelAbstract
{
    protected static function channelName(): string
    {
        return 'FfmpegQueueChannel';
    }

    public static function eventInterface(): SelectEventInterface
    {
        return static::$channels[static::channelName()];
    }

    protected static function dataInterface(): ?string
    {
        return FfmpegQueueGlobalData::class;
    }

    protected static function config(): array
    {
        return [
            $_ENV['FFMPEG_QUEUE_CHANNEL_HOST'],
            $_ENV['FFMPEG_QUEUE_CHANNEL_PORT'],
        ];
    }
}
