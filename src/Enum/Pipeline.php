<?php

declare(strict_types=1);

namespace WsFramework\Enum;

use WsFramework\Trait\EnumTrait;

enum Pipeline: string
{
    use EnumTrait;
    case S3_DOWNLOAD = 's3_download';
    case FFMPEG      = 'ffmpeg';
    case S3_UPLOAD   = 's3_upload';
    case BROWSERLESS = 'browserless';
}
