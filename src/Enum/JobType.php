<?php

declare(strict_types=1);

namespace WsFramework\Enum;

use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;

enum JobType: string
{
    case FFMPEG = 'ffmpeg';
    case BROWSERLESS = 'browserless';

    /**
     * @return NatsKeyValueInterface
     * @throws \Throwable
     */
    public function kvBucket(): NatsKeyValueInterface
    {
        return match ($this) {
            self::FFMPEG => KVNatsBucket::bucketInterface()->bucket('ffmpeg_jobs_status'),
            self::BROWSERLESS => KVNatsBucket::bucketInterface()->bucket('browserless_jobs_status'),
        };
    }

    /**
     * @return class-string<JobStatusInterface>
     */
    public function statusClass(): string
    {
        return match ($this) {
            self::FFMPEG => FfmpegJobStatus::class,
            self::BROWSERLESS => BrowserlessJobStatus::class,
        };
    }

    public function childKey(): string
    {
        return match ($this) {
            self::FFMPEG => 'ffmpegJob',
            self::BROWSERLESS => 'browserlessJob',
        };
    }

    public function restartedStatus(Pipeline $pipeline): JobStatusInterface
    {
        return match ($this) {
            self::FFMPEG => match ($pipeline) {
                Pipeline::S3_DOWNLOAD => FfmpegJobStatus::S3_DOWNLOAD_RESTARTED,
                Pipeline::FFMPEG => FfmpegJobStatus::PROCESSING_RESTARTED,
                Pipeline::S3_UPLOAD => FfmpegJobStatus::S3_UPLOAD_RESTARTED,
            },
            self::BROWSERLESS => match ($pipeline) {
                Pipeline::S3_UPLOAD => BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_RESTARTED,
                Pipeline::BROWSERLESS => BrowserlessJobStatus::BROWSERLESS_PROCESSING_RESTARTED,
            },
        };
    }

    public function failedStatus(Pipeline $pipeline): JobStatusInterface
    {
        return match ($this) {
            self::FFMPEG => match ($pipeline) {
                Pipeline::S3_DOWNLOAD => FfmpegJobStatus::S3_DOWNLOAD_FAILED,
                Pipeline::S3_UPLOAD => FfmpegJobStatus::S3_UPLOAD_FAILED,
                Pipeline::FFMPEG => FfmpegJobStatus::FAILED,
            },
            self::BROWSERLESS => match ($pipeline) {
                Pipeline::S3_UPLOAD => BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_FAILED,
                Pipeline::BROWSERLESS => BrowserlessJobStatus::BROWSERLESS_FAILED,
            },
        };
    }

    public static function fromStatusEnum(JobStatusInterface $e): self
    {
        return match (true) {
            $e instanceof FfmpegJobStatus => self::FFMPEG,
            $e instanceof BrowserlessJobStatus => self::BROWSERLESS,
            default => self::FFMPEG,
        };
    }

    /**
     * @return string[]
     */
    public static function allChildKeys(): array
    {
        return array_map(fn(self $case) => $case->childKey(), self::cases());
    }
}
