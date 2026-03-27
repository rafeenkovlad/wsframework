<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\NatsSubject;

class DispatchJobByStatusUseCase
{
    public static function handle(JobKVDTO $jobKVDTO): void
    {
        $payload = $jobKVDTO->jsonEncode();
        echo $jobKVDTO->status;
        echo PHP_EOL;

        $subject = match ($jobKVDTO->status) {
            FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,
            FfmpegJobStatus::S3_DOWNLOAD_RESTARTED->value => NatsSubject::S3_DOWNLOAD,

            FfmpegJobStatus::PENDING->value,
            FfmpegJobStatus::PROCESSING_RESTARTED->value => NatsSubject::FFMPEG_JOB,

            FfmpegJobStatus::S3_UPLOAD_PENDING->value,
            FfmpegJobStatus::S3_UPLOAD_RESTARTED->value => NatsSubject::S3_UPLOAD,

            default => null,
        };

        if ($subject !== null) {
            $subject->channelClass()::eventInterface()->publish($payload, $subject->value);
        }
    }
}
