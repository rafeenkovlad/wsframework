<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\JobType;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Trait\JobIdValidationTrait;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\JobKVMergeUseCase;

class RestartJob extends MethodAbstract
{
    use JobIdValidationTrait;
    public static function getMethodName(): string
    {
        return 'FfmpegQueue.RestartJob';
    }

    public static function getResponseClass(): string
    {
        return Ok::class;
    }

    public static function getChannelClass(): ?string
    {
        return null;
    }

    public static function getPoolConnectionClass(): ?string
    {
        return PoolHttpConnection::class;
    }

    public static function isDisabledResponse(): bool
    {
        return false;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        $jobId = static::extractJobId($methodDTO);
        if ($jobId === null) {
            return [];
        }

        $kv = JobType::FFMPEG->kvBucket();
        $existing = $kv->get($jobId);

        if (!$existing) {
            $methodDTO->response->errors = [['field' => 'jobId', 'message' => 'Job not found']];
            return [];
        }

        $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
        $status = $jobData->status ?? '';

        $restartable = [
            FfmpegJobStatus::FAILED->value,
            FfmpegJobStatus::CANCELLED->value,
            FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
            FfmpegJobStatus::S3_UPLOAD_FAILED->value,
            FfmpegJobStatus::S3_DOWNLOADING->value,
            FfmpegJobStatus::S3_UPLOADING->value,
            FfmpegJobStatus::PROCESSING->value,
        ];
        if (!in_array($status, $restartable, true)) {
            $methodDTO->response->errors = [[
                'field' => 'status',
                'message' => "Only failed, cancelled or stuck jobs can be restarted, current status: {$status}",
            ]];
            return [];
        }

        // Determine target pending status by current status
        $targetStatus = match ($status) {
            FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
            FfmpegJobStatus::S3_DOWNLOADING->value => FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,

            FfmpegJobStatus::S3_UPLOAD_FAILED->value,
            FfmpegJobStatus::S3_UPLOADING->value => FfmpegJobStatus::S3_UPLOAD_PENDING->value,

            default => null,
        };

        // failed / cancelled → restart from ffmpeg stage (only if inputFile exists)
        if ($targetStatus === null) {
            $inputFile = $jobData->s3Download?->inputFile;
            if (!$inputFile) {
                $methodDTO->response->errors = [[
                    'field' => 'inputFile',
                    'message' => 'Job has no inputFile in KV. Re-submit the job.',
                ]];
                return [];
            }
            $targetStatus = FfmpegJobStatus::PENDING->value;
        }

        $jobKVDTO = new JobKVDTO(
            jobId: $jobId,
            status: $targetStatus,
            retryCount: 0,
        );

        JobKVMergeUseCase::handle($jobKVDTO);

        DispatchJobByStatusUseCase::handle($jobKVDTO);

        return ['jobId' => $jobId, 'restarted' => true, 'status' => $targetStatus];
    }

    protected static function getDescription(): string
    {
        return 'Перезапустить задачу со статусом failed, cancelled или зависшую (stuck downloading/uploading/processing).';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            OpenRpcSchema::jobIdDescriptor(),
        ];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['jobId', 'restarted', 'status'],
            'additionalProperties' => false,
            'properties' => [
                'jobId' => [
                    'type' => 'string',
                    'description' => 'Идентификатор задачи, поставленной в очередь повторно.',
                ],
                'restarted' => [
                    'type' => 'boolean',
                    'description' => 'Признак успешного повторного запуска.',
                ],
                'status' => OpenRpcSchema::statusSchema('Новый статус задачи после перезапуска.'),
            ],
        ];
    }
}
