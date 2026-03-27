<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use Basis\Nats\KeyValue\Entry;
use JsonException;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;

class RecoverStuckJobsUseCase
{

    /**
     * @param array<FfmpegJobStatus, string> $statusMap ['S3_DOWNLOADING']
     * @return void
     * @throws PipelineException
     * @throws JsonException
     * @throws UseCaseException|\Throwable
     */
    public static function handle(array $statusMap): void
    {
        $kv = GetKVInterfaceUseCase::handle();
        $recovered = 0;
        $entries = $kv->getAll();

        /** @var Entry $entry */
        foreach ($entries as $entry) {
            $jobKVDTO = JobKVDTO::createFromArray(json_decode($entry->value, true)) ?? JobKVDTO::createWithDefaultValues();

            if (!in_array(FfmpegJobStatus::fromString($jobKVDTO->status), $statusMap)) {
                continue;
            }

            if (in_array($jobKVDTO->jobId, ['undefined', '', null])) {
                $kv->delete($jobKVDTO->jobId);
                continue;
            }

            $status = static::match(FfmpegJobStatus::fromString($jobKVDTO->status));

            JobKVMergeUseCase::handle(
                new JobKVDTO(jobId: $jobKVDTO->jobId, status: $status->getValue()),
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
     * @param FfmpegJobStatus $status
     * @return FfmpegJobStatus
     * @throws PipelineException
     */
    private static function match(FfmpegJobStatus $status): FfmpegJobStatus
    {
        return match ($status) {
            FfmpegJobStatus::S3_DOWNLOADING, FfmpegJobStatus::S3_DOWNLOAD_PENDING, FfmpegJobStatus::S3_DOWNLOAD_RESTARTED
            => FfmpegJobStatus::S3_DOWNLOAD_RESTARTED,
            FfmpegJobStatus::S3_UPLOADING, FfmpegJobStatus::S3_UPLOAD_PENDING, FfmpegJobStatus::S3_UPLOAD_RESTARTED
            => FfmpegJobStatus::S3_UPLOAD_RESTARTED,
            FfmpegJobStatus::PROCESSING, FfmpegJobStatus::PENDING, FfmpegJobStatus::PROCESSING_RESTARTED
            => FfmpegJobStatus::PROCESSING_RESTARTED,
            default => throw new PipelineException(DefineCurrentPipelineUseCase::handle()->getName(), 'Recovery is not supported for this status.')
        };
    }
}
