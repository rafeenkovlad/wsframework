<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\BrowserlessQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\KVMergeOptionsDTO;
use WsFramework\Enum\BrowserlessJobStatus;
use WsFramework\Enum\JobType;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Trait\JobIdValidationTrait;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\JobKVMergeUseCase;

class RestartJob extends MethodAbstract
{
    use JobIdValidationTrait;

    public static function getMethodName(): string
    {
        return 'BrowserlessQueue.RestartJob';
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

        $kv = JobType::BROWSERLESS->kvBucket();
        $existing = $kv->get($jobId);

        if (!$existing) {
            $methodDTO->response->errors = [['field' => 'jobId', 'message' => 'Job not found']];
            return [];
        }

        $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
        $status = $jobData->status ?? '';

        $restartable = [
            BrowserlessJobStatus::BROWSERLESS_FAILED->value,
            BrowserlessJobStatus::BROWSERLESS_CANCELLED->value,
            BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_FAILED->value,
            BrowserlessJobStatus::BROWSERLESS_PROCESSING->value,
            BrowserlessJobStatus::BROWSERLESS_S3_UPLOADING->value,
        ];
        if (!in_array($status, $restartable, true)) {
            $methodDTO->response->errors = [[
                'field' => 'status',
                'message' => "Only failed, cancelled or stuck jobs can be restarted, current status: {$status}",
            ]];
            return [];
        }

        $targetStatus = match ($status) {
            BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_FAILED->value,
            BrowserlessJobStatus::BROWSERLESS_S3_UPLOADING->value => BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_PENDING->value,
            default => BrowserlessJobStatus::BROWSERLESS_PENDING->value,
        };

        $jobKVDTO = new JobKVDTO(
            jobId: $jobId,
            status: $targetStatus,
            retryCount: 0,
        );

        JobKVMergeUseCase::handle($jobKVDTO, KVMergeOptionsDTO::createFromArray(['jobType' => JobType::BROWSERLESS]));

        DispatchJobByStatusUseCase::handle($jobKVDTO);

        return ['jobId' => $jobId, 'restarted' => true, 'status' => $targetStatus];
    }

    protected static function getDescription(): string
    {
        return 'Перезапустить browserless-задачу со статусом failed, cancelled или зависшую (stuck processing/uploading).';
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
