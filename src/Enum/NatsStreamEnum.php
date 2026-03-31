<?php

declare(strict_types=1);

namespace WsFramework\Enum;


use WsFramework\Trait\EnumTrait;

enum NatsStreamEnum: string
{
    use EnumTrait;

    case S3_DOWNLOAD = 's3_download';
    case S3_UPLOAD = 's3_upload';
    case FFMPEG_JOB = 'ffmpeg_jobs';
}
