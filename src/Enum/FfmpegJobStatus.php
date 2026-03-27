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

    public function isRestartable(): bool
    {
        return in_array($this, [
            self::PENDING, self::S3_DOWNLOAD_PENDING, self::S3_UPLOAD_PENDING,
            self::S3_DOWNLOAD_RESTARTED, self::PROCESSING_RESTARTED, self::S3_UPLOAD_RESTARTED,
        ]);
    }

    public function pipeline(): Pipeline
    {
        return match ($this) {
            self::S3_DOWNLOAD_PENDING,
            self::S3_DOWNLOADING,
            self::S3_DOWNLOAD_FAILED,
            self::S3_DOWNLOAD_RESTARTED
                => Pipeline::S3_DOWNLOAD,

            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::PROCESSING_RESTARTED
                => Pipeline::FFMPEG,

            self::S3_UPLOAD_PENDING,
            self::S3_UPLOADING,
            self::S3_UPLOAD_FAILED,
            self::S3_UPLOAD_RESTARTED
                => Pipeline::S3_UPLOAD,
        };
    }
}
