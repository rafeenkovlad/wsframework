<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\BrowserlessJobStatus;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\NatsSubjectEnum;

class DispatchJobByStatusUseCase
{
    public static function handle(JobKVDTO $jobKVDTO): void
    {
        $payload = $jobKVDTO->jsonEncode();
        echo $jobKVDTO->status;
        echo PHP_EOL;

        $subjectEnum = match ($jobKVDTO->status) {
            FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,
            FfmpegJobStatus::S3_DOWNLOAD_RESTARTED->value => NatsSubjectEnum::S3_DOWNLOAD,

            FfmpegJobStatus::PENDING->value,
            FfmpegJobStatus::PROCESSING_RESTARTED->value => NatsSubjectEnum::FFMPEG_JOB,

            FfmpegJobStatus::S3_UPLOAD_PENDING->value,
            FfmpegJobStatus::S3_UPLOAD_RESTARTED->value => NatsSubjectEnum::S3_UPLOAD,

            BrowserlessJobStatus::BROWSERLESS_PENDING->value,
            BrowserlessJobStatus::BROWSERLESS_PROCESSING_RESTARTED->value => NatsSubjectEnum::BROWSERLESS_JOB,

            BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_PENDING->value,
            BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_RESTARTED->value => NatsSubjectEnum::S3_UPLOAD,

            default => null,
        };

        if ($subjectEnum !== null) {
            DefineCurrentChannelUseCase::handle()->publish($payload, $subjectEnum->getValue());
        }
    }
}
