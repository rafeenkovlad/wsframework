<?php

declare(strict_types=1);

namespace WsFramework\Enum;

use WsFramework\Trait\EnumTrait;

enum BrowserlessJobStatus: string implements JobStatusInterface
{
    use EnumTrait;

    case BROWSERLESS_PENDING              = 'browserless_pending';
    case BROWSERLESS_PROCESSING           = 'browserless_processing';
    case BROWSERLESS_PROCESSING_RESTARTED = 'browserless_processing_restarted';
    case BROWSERLESS_S3_UPLOAD_PENDING    = 'browserless_s3_upload_pending';
    case BROWSERLESS_S3_UPLOADING         = 'browserless_s3_uploading';
    case BROWSERLESS_S3_UPLOAD_FAILED     = 'browserless_s3_upload_failed';
    case BROWSERLESS_S3_UPLOAD_RESTARTED  = 'browserless_s3_upload_restarted';
    case BROWSERLESS_COMPLETED            = 'browserless_completed';
    case BROWSERLESS_FAILED               = 'browserless_failed';
    case BROWSERLESS_CANCELLED            = 'browserless_cancelled';

    public function pipeline(): Pipeline
    {
        return match ($this) {
            self::BROWSERLESS_S3_UPLOAD_PENDING,
            self::BROWSERLESS_S3_UPLOADING,
            self::BROWSERLESS_S3_UPLOAD_FAILED,
            self::BROWSERLESS_S3_UPLOAD_RESTARTED => Pipeline::S3_UPLOAD,
            default => Pipeline::BROWSERLESS,
        };
    }

    public function isRestartable(): bool
    {
        return in_array($this, [
            self::BROWSERLESS_PENDING,
            self::BROWSERLESS_PROCESSING_RESTARTED,
            self::BROWSERLESS_S3_UPLOAD_PENDING,
            self::BROWSERLESS_S3_UPLOAD_RESTARTED,
            self::BROWSERLESS_S3_UPLOAD_FAILED,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array(
            $this,
            [
                self::BROWSERLESS_COMPLETED,
                self::BROWSERLESS_FAILED,
                self::BROWSERLESS_CANCELLED,
                self::BROWSERLESS_PROCESSING_RESTARTED,
            ]
        );
    }
}
