<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;

class DispatchJobByStatusUseCase
{
    public static function handle(JobKVDTO $jobKVDTO, NatsKeyValueInterface $kv): void
    {
        $existing = $kv->get($jobKVDTO->jobId);

        if (!$existing) {
            return;
        }

        $job = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
        $payload = StagePayloadDTO::createFromArray(['jobId' => $job->jobId])->jsonEncode();

        match ($job->status) {
            FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,
            FfmpegJobStatus::S3_DOWNLOAD_RESTARTED->value => S3NatsChannel::eventInterface()->publish(
                $payload,
                S3NatsChannel::METHOD_DOWNLOAD,
            ),

            FfmpegJobStatus::PENDING->value,
            FfmpegJobStatus::PROCESSING_RESTARTED->value => FfmpegNatsChannel::eventInterface()->publish(
                $payload,
                FfmpegNatsChannel::METHOD_JOB,
            ),

            FfmpegJobStatus::S3_UPLOAD_PENDING->value,
            FfmpegJobStatus::S3_UPLOAD_RESTARTED->value => S3NatsChannel::eventInterface()->publish(
                $payload,
                S3NatsChannel::METHOD_UPLOAD,
            ),

            default => null,
        };
    }

    /**
     * @param JobKVDTO $jobFromKV
     * @param JobKVDTO $jobCurrent
     * @return bool
     */
    private static function checkEqStatus(JobKVDTO $jobFromKV, JobKVDTO $jobCurrent): bool
    {
        if ($jobFromKV->status === $jobCurrent->status) {
            return true;
        }

        sleep(1);

        return false;
    }
}
