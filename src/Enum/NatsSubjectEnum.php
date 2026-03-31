<?php

declare(strict_types=1);

namespace WsFramework\Enum;

use WsFramework\Trait\EnumTrait;

enum NatsSubjectEnum: string
{
    use EnumTrait;

    case S3_DOWNLOAD = 's3Pipeline.download';
    case S3_UPLOAD   = 's3Pipeline.upload';
    case FFMPEG_JOB  = 'ffmpegQueue.addJob';

    public function stream(): NatsStreamEnum
    {
        return match ($this) {
            self::S3_DOWNLOAD => NatsStreamEnum::S3_DOWNLOAD,
            self::S3_UPLOAD   => NatsStreamEnum::S3_UPLOAD,
            self::FFMPEG_JOB  => NatsStreamEnum::FFMPEG_JOB,
        };
    }

    /**
     * Конфиг для NatsClient::init().
     *
     * @return array{stream: string, name: string}
     */
    public function toConfig(): array
    {
        return [
            'stream' => $this->stream()->getValue(),
            'name' => $this->getValue(),
        ];
    }

    /**
     * Полный конфиг всех subjects для NatsClient::init().
     *
     * @return list<array{stream: string, name: string}>
     */
    public static function allConfigs(): array
    {
        return array_map(
            static fn(self $case): array => $case->toConfig(),
            self::cases(),
        );
    }
}
