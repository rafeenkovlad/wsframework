<?php

namespace WsFramework\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\UseCase\FfmpegJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\S3DownloadJobDTO;
use WsFramework\Dto\UseCase\S3UploadJobDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\Pipeline;
use WsFramework\Exception\S3\PipelineException;

class ThrowableHandleUseCase extends AbstractUseCase
{
    /**
     * @param JobKVDTO $DTO
     * @param ...$args
     * @return void
     * @throws \Throwable
     */
    public static function handle(DataTransferObject $DTO, ...$args): void
    {
        $static = static::create($DTO);

        foreach ($args as $arg) {

            if ($arg instanceof \Throwable) {
                $static->throw($arg);
                continue;
            }
            if ($arg) {
                throw new PipelineException(DefineCurrentPipelineUseCase::handle()->getName(), 'Current status: ' . $DTO->status . 'Only throwable arguments are allowed');
            }
        }
    }

    /**
     * @param \Throwable $e
     * @return void
     * @throws \JsonException
     * @throws \Throwable
     * @throws \WsFramework\Exception\UseCaseException
     * @throws PipelineException
     */
    private function throw(\Throwable $e): void
    {
        /** @var JobKVDTO $DTO */
        $DTO = $this->DTO;
        $pipeline = FfmpegJobStatus::fromString($DTO->status)->pipeline();

        $status = null;
        $errorDTO = null;
        $errorMessage = [['message' => $e->getMessage(), 'at' => date('c')]];
        if ($pipeline === Pipeline::S3_DOWNLOAD) {
            $status = $this->mayByFailed($pipeline) ?? FfmpegJobStatus::S3_DOWNLOAD_RESTARTED;
            $errorDTO = new S3DownloadJobDTO(errors: $errorMessage);
        } elseif ($pipeline === Pipeline::FFMPEG) {
            $status = $this->mayByFailed($pipeline) ?? FfmpegJobStatus::PROCESSING_RESTARTED;
            $errorDTO = new FfmpegJobDTO(errors: $errorMessage);
        } elseif ($pipeline === Pipeline::S3_UPLOAD) {
            $status = $this->mayByFailed($pipeline)  ?? FfmpegJobStatus::S3_UPLOAD_RESTARTED;
            $errorDTO = new S3UploadJobDTO(errors: $errorMessage);
        }

        JobKVMergeUseCase::handle(
            new JobKVDTO(
                jobId: $DTO->jobId,
                status: $status?->getName(),
                retryCount: $DTO->retryCount + 1,
                finishedAt: date('c'),
                s3Download: $errorDTO,
            ),
        );

        $this->mayBePipelineException($pipeline, $e);
    }

    /**
     * @param Pipeline $pipeline
     * @return FfmpegJobStatus|null
     */
    private function mayByFailed(Pipeline $pipeline): ?FfmpegJobStatus
    {
        /** @var JobKVDTO $DTO */
        $DTO = $this->DTO;
        if ($DTO->maxRetries <= $DTO->retryCount + 1) {
            if ($pipeline === Pipeline::S3_DOWNLOAD) {
                return FfmpegJobStatus::S3_DOWNLOAD_FAILED;
            } elseif ($pipeline === Pipeline::FFMPEG) {
                return FfmpegJobStatus::FAILED;
            } elseif ($pipeline === Pipeline::S3_UPLOAD) {
                return FfmpegJobStatus::S3_UPLOAD_FAILED;
            }
        }

        return null;
    }

    /**
     * @param Pipeline $pipeline
     * @param \Throwable $e
     * @return void
     * @throws PipelineException
     * @throws \Throwable
     */
    private function mayBePipelineException(Pipeline $pipeline, \Throwable $e): void
    {
        /** @var JobKVDTO $DTO */
        $DTO = $this->DTO;
        if ($DTO->maxRetries <= $DTO->retryCount + 1) {
            throw new PipelineException($pipeline->getName(), $e->getMessage());
        }

        throw $e;
    }
}