<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use Basis\Nats\KeyValue\Entry;
use JsonException;
use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;

class RecoverStuckJobsUseCase
{

    /**
     * @param NatsKeyValueInterface $kv
     * @param array<FfmpegJobStatus, string> $statusMap ['S3_DOWNLOADING']
     * @return void
     * @throws PipelineException
     * @throws JsonException
     * @throws UseCaseException
     */
    public static function handle(NatsKeyValueInterface $kv, array $statusMap): void
    {
        $recovered = 0;
        $entries = $kv->getAll();

        /** @var Entry $entry */
        foreach ($entries as $entry) {
            $jobKVDTO = JobKVDTO::createFromArray(json_decode($entry->value, true)) ?? JobKVDTO::createWithDefaultValues();

            if (!in_array($jobKVDTO->status, $statusMap)) {
                continue;
            }

            $status = static::match(FfmpegJobStatus::fromString($jobKVDTO->status));

            JobKVMergeUseCase::handle(
                new JobKVDTO(jobId: $jobKVDTO->jobId, status: $status->getValue()),
                $kv,
            );
            DispatchJobByStatusUseCase::handle(
                $jobKVDTO->jobId,
                $kv,
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
           FfmpegJobStatus::S3_DOWNLOADING => FfmpegJobStatus::S3_DOWNLOAD_RESTARTED,
           FfmpegJobStatus::S3_UPLOADING => FfmpegJobStatus::S3_UPLOAD_RESTARTED,
           FfmpegJobStatus::PROCESSING => FfmpegJobStatus::PROCESSING_RESTARTED,
           default => throw new PipelineException($status->getValue(), 'Recovery is not supported for this status.')
       };
    }
}
