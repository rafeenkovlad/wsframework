<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use Basis\Nats\KeyValue\Entry;
use JsonException;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\KVMergeOptionsDTO;
use WsFramework\Enum\BrowserlessJobStatus;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\JobStatusInterface;
use WsFramework\Enum\JobType;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;

class RecoverStuckJobsUseCase
{

    /**
     * @param array<JobStatusInterface> $statusMap
     * @return void
     * @throws PipelineException
     * @throws JsonException
     * @throws UseCaseException|\Throwable
     */
    public static function handle(array $statusMap): void
    {
        if (empty($statusMap)) {
            return;
        }

        $jobType = null;
        foreach ($statusMap as $status) {
            if (!is_null($jobType) && $jobType !== JobType::fromStatusEnum($status)) {
                throw new PipelineException(
                    DefineCurrentPipelineUseCase::handle()->getName(),
                    'All statuses must be of the same job type.'
                );
            }
            $jobType = JobType::fromStatusEnum($status);

        }


        $kv = $jobType->kvBucket();
        $recovered = 0;
        $entries = $kv->getAll();

        /** @var Entry $entry */
        foreach ($entries as $entry) {
            $jobKVDTO = JobKVDTO::createFromArray(json_decode($entry->value, true)) ?? JobKVDTO::createWithDefaultValues();

            $currentStatus = $jobType->statusClass()::tryFrom($jobKVDTO->status);

            if ($currentStatus === null || !in_array($currentStatus, $statusMap)) {
                continue;
            }

            if (in_array($jobKVDTO->jobId, ['undefined', '', null])) {
                $kv->delete($jobKVDTO->jobId);
                continue;
            }

            $status = static::match($currentStatus);

            JobKVMergeUseCase::handle(
                new JobKVDTO(jobId: $jobKVDTO->jobId, status: $status->getValue()),
                KVMergeOptionsDTO::createFromArray([
                    'jobType' => $jobType,
                ]),
            );
            DispatchJobByStatusUseCase::handle(
                $jobKVDTO,
            );
            $recovered++;

            echo "Recovered: " . $jobKVDTO->jobId . "\n";
        }

        echo "Recovered total: " . $recovered . "\n";
    }

    /**
     * @param JobStatusInterface $status
     * @return JobStatusInterface
     * @throws PipelineException
     */
    private static function match(JobStatusInterface $status): JobStatusInterface
    {
        return match ($status) {
            FfmpegJobStatus::S3_DOWNLOADING, FfmpegJobStatus::S3_DOWNLOAD_PENDING, FfmpegJobStatus::S3_DOWNLOAD_RESTARTED
            => FfmpegJobStatus::S3_DOWNLOAD_RESTARTED,
            FfmpegJobStatus::S3_UPLOADING, FfmpegJobStatus::S3_UPLOAD_PENDING, FfmpegJobStatus::S3_UPLOAD_RESTARTED
            => FfmpegJobStatus::S3_UPLOAD_RESTARTED,
            FfmpegJobStatus::PROCESSING, FfmpegJobStatus::PENDING, FfmpegJobStatus::PROCESSING_RESTARTED
            => FfmpegJobStatus::PROCESSING_RESTARTED,
            BrowserlessJobStatus::BROWSERLESS_PROCESSING, BrowserlessJobStatus::BROWSERLESS_PENDING, BrowserlessJobStatus::BROWSERLESS_PROCESSING_RESTARTED
            => BrowserlessJobStatus::BROWSERLESS_PROCESSING_RESTARTED,
            BrowserlessJobStatus::BROWSERLESS_S3_UPLOADING, BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_PENDING, BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_RESTARTED
            => BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_RESTARTED,
            default => throw new PipelineException(DefineCurrentPipelineUseCase::handle()->getName(), 'Recovery is not supported for this status.')
        };
    }
}
