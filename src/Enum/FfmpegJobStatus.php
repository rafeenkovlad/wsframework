<?php

declare(strict_types=1);

namespace WsFramework\Enum;

use WsFramework\Trait\EnumTrait;

enum FfmpegJobStatus: string
{
    use EnumTrait;
    case S3_DOWNLOAD_PENDING = 's3_download_pending';
    case S3_DOWNLOADING      = 's3_downloading';
    case S3_DOWNLOAD_FAILED  = 's3_download_failed';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case S3_UPLOAD_PENDING = 's3_upload_pending';
    case S3_UPLOADING      = 's3_uploading';
    case S3_UPLOAD_FAILED  = 's3_upload_failed';
    case S3_DOWNLOAD_RESTARTED = 's3_download_restarted';
    case PROCESSING_RESTARTED  = 'processing_restarted';
    case S3_UPLOAD_RESTARTED   = 's3_upload_restarted';
}
