<?php

namespace WsFramework\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\UseCase\BrowserlessJobDTO;
use WsFramework\Dto\UseCase\FfmpegJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\KVMergeOptionsDTO;
use WsFramework\Dto\UseCase\S3DownloadJobDTO;
use WsFramework\Dto\UseCase\S3UploadJobDTO;
use WsFramework\Enum\JobStatusInterface;
use WsFramework\Enum\JobType;
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
        $jobType = $DTO->resolveJobType();
        $statusEnum = $jobType->statusClass()::tryFrom($DTO->status);
        $pipeline = $statusEnum?->pipeline() ?? Pipeline::S3_UPLOAD;

        $status = $this->mayByFailed($jobType, $pipeline) ?? $jobType->restartedStatus($pipeline);

        $errorMessage = [['message' => $e->getMessage(), 'at' => date('c')]];
        $errorDTO = match ($pipeline) {
            Pipeline::S3_DOWNLOAD => new S3DownloadJobDTO(errors: $errorMessage),
            Pipeline::FFMPEG => new FfmpegJobDTO(errors: $errorMessage),
            Pipeline::S3_UPLOAD => new S3UploadJobDTO(errors: $errorMessage),
            Pipeline::BROWSERLESS => new BrowserlessJobDTO(errors: $errorMessage),
        };

        $childKey = match ($pipeline) {
            Pipeline::BROWSERLESS => 'browserlessJob',
            Pipeline::FFMPEG => 'ffmpegJob',
            Pipeline::S3_DOWNLOAD => 's3Download',
            Pipeline::S3_UPLOAD => 's3Upload',
        };

        $mergeDTO = new JobKVDTO(
            jobId: $DTO->jobId,
            status: $status->getValue(),
            retryCount: ($DTO->retryCount ?? 0) + 1,
            finishedAt: date('c'),
            s3Download: $childKey === 's3Download' ? $errorDTO : null,
            ffmpegJob: $childKey === 'ffmpegJob' ? $errorDTO : null,
            s3Upload: $childKey === 's3Upload' ? $errorDTO : null,
            browserlessJob: $childKey === 'browserlessJob' ? $errorDTO : null,
        );

        JobKVMergeUseCase::handle(
            $mergeDTO,
            KVMergeOptionsDTO::createFromArray([
                'jobType' => $jobType,
            ]),
        );

        $this->mayBePipelineException($pipeline, $e);
    }

    /**
     * @param JobType $jobType
     * @param Pipeline $pipeline
     * @return JobStatusInterface|null
     */
    private function mayByFailed(JobType $jobType, Pipeline $pipeline): ?JobStatusInterface
    {
        /** @var JobKVDTO $DTO */
        $DTO = $this->DTO;
        if (($DTO->maxRetries ?? 0) <= ($DTO->retryCount ?? 0) + 1) {
            return $jobType->failedStatus($pipeline);
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
        if (($DTO->maxRetries ?? 0) <= ($DTO->retryCount ?? 0) + 1) {
            throw new PipelineException($pipeline->getName(), $e->getMessage());
        }

        throw $e;
    }
}
