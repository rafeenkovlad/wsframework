<?php

declare(strict_types=1);

namespace WsFramework\Service\Video;

use JsonException;
use WsFramework\Dto\UseCase\FfmpegJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\ClaimResult;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\Pipeline;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\UseCase\ClaimJobStageUseCase;
use WsFramework\UseCase\DefineCurrentPipelineUseCase;
use WsFramework\UseCase\GetKVInterfaceUseCase;
use WsFramework\UseCase\JobKVMergeUseCase;
use WsFramework\UseCase\ThrowableHandleUseCase;

readonly class FfmpegJobExecutor
{
    public function __construct(
        private FfmpegVideoConverter $converter,
        private string $filesDirectory,
    ) {}

    /**
     * @param string $jobId
     * @return void
     * @throws JsonException
     * @throws PipelineException
     * @throws UseCaseException
     */
    private function checkClaimedStart(string $jobId): void
    {
        if (!$jobId) {
            throw new PipelineException(DefineCurrentPipelineUseCase::handle()->getName(), 'missing jobId');
        }

        $result = ClaimJobStageUseCase::handle(
            new JobKVDTO(jobId: $jobId),
            allowedStatuses: [
                FfmpegJobStatus::PENDING->value,
                FfmpegJobStatus::PROCESSING_RESTARTED->value,
            ],
            activeStatus: FfmpegJobStatus::PROCESSING->value,
        );

        if ($result !== ClaimResult::CLAIMED) {
            $ex = new PipelineException(DefineCurrentPipelineUseCase::handle()->getName(), "job {$jobId} skip — {$result->name}\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    /**
     * @param string $jobId
     * @return void
     * @throws JsonException
     * @throws PipelineException
     */
    private function checkFailed(string $jobId): void
    {
        $kv = GetKVInterfaceUseCase::handle();
        $existing = $kv->get($jobId);
        $jobKVDTO = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        if (FfmpegJobStatus::FAILED->isEq($jobKVDTO->status)) {
            $ex = new PipelineException('FfmpegQueueProcess', "job {$jobId} skip — FAILED\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    /**
     * @param string $jobId
     * @return JobKVDTO
     * @throws JsonException
     * @throws PipelineException
     * @throws UseCaseException
     * @throws \Throwable
     */
    public function execute(string $jobId): JobKVDTO
    {
        $kv = GetKVInterfaceUseCase::handle();
        $this->checkFailed($jobId);
        $this->checkClaimedStart($jobId);

        $existing = $kv->get($jobId);
        $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
        $inputFile = $jobData->s3Download?->inputFile;

        try {
            if (!is_string($inputFile) || trim($inputFile) === '') {
                throw new PipelineException(Pipeline::FFMPEG->getName(), "inputFile is required for job {$jobId}");
            }

            $dimensions = $this->converter->getDimensions($inputFile);
            $outputFile = $this->converter->convertToHls(
                $inputFile,
                $dimensions['width'],
                $dimensions['height'],
            );

            $localHlsDir = $this->filesDirectory . dirname($outputFile) . '/';
            $playlistFile = basename($outputFile);
            $tsFiles = glob($localHlsDir . '*.ts');
            $segmentCount = count($tsFiles ?: []);

            $jobData = new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::S3_UPLOAD_PENDING->value,
                ffmpegJob: new FfmpegJobDTO(
                    outputFile: $outputFile,
                    localHlsDir: $localHlsDir,
                    playlistFile: $playlistFile,
                    segmentCount: $segmentCount,
                ),
            );

            JobKVMergeUseCase::handle($jobData);

            echo "FfmpegJobExecutor: job {$jobId} converted, ready for s3_upload\n";

            return $jobData;
        } catch (\Throwable $e) {
            echo "FfmpegJobExecutor: job {$jobId} retry {$jobData->retryCount}/{$jobData->maxRetries}\n";
            ThrowableHandleUseCase::handle($jobData, $e);

            return $jobData;
        }
    }
}
