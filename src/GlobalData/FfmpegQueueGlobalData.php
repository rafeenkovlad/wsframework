<?php

declare(strict_types=1);

namespace WsFramework\GlobalData;

class FfmpegQueueGlobalData extends GlobalDataAbstract
{
    protected static function globalDataName(): string
    {
        return 'FfmpegQueueGlobalData';
    }

    protected static function config(): array
    {
        return [
            $_ENV['FFMPEG_QUEUE_GLOBALDATA_HOST'],
            $_ENV['FFMPEG_QUEUE_GLOBALDATA_PORT'],
        ];
    }

    public static function service(): static
    {
        return static::$globalsData[static::globalDataName()];
    }
}
