<?php

declare(strict_types=1);

namespace WsFramework\Enum;

use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;

enum NatsSubject: string
{
    case S3_DOWNLOAD = 's3Pipeline.download';
    case S3_UPLOAD   = 's3Pipeline.upload';
    case FFMPEG_JOB  = 'FfmpegQueue.AddJob';

    public function stream(): string
    {
        return match ($this) {
            self::S3_DOWNLOAD => 's3_download',
            self::S3_UPLOAD   => 's3_upload',
            self::FFMPEG_JOB  => 'ffmpeg_jobs',
        };
    }

    public function channelClass(): string
    {
        return match ($this) {
            self::S3_DOWNLOAD,
            self::S3_UPLOAD   => S3NatsChannel::class,
            self::FFMPEG_JOB  => FfmpegNatsChannel::class,
        };
    }
}
