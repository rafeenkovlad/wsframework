<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Trait\FfmpegJobIdValidationTrait;

class DeleteJob extends MethodAbstract
{
    use FfmpegJobIdValidationTrait;
    public static function getMethodName(): string
    {
        return 'ffmpegQueue.deleteJob';
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

        $kv = FfmpegNatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
        $existing = $kv->get($jobId);

        if (!$existing) {
            $methodDTO->response->errors = [['field' => 'jobId', 'message' => 'Job not found']];
            return [];
        }

        $jobData = json_decode($existing, true, 512, JSON_THROW_ON_ERROR);
        $status = $jobData['status'] ?? '';

        $terminal = [
            FfmpegJobStatus::COMPLETED->value,
            FfmpegJobStatus::FAILED->value,
            FfmpegJobStatus::CANCELLED->value,
            FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
            FfmpegJobStatus::S3_UPLOAD_FAILED->value,
        ];
        if (!in_array($status, $terminal, true)) {
            $methodDTO->response->errors = [[
                'field' => 'status',
                'message' => "Only terminal jobs (completed/failed/cancelled) can be deleted, current status: {$status}",
            ]];
            return [];
        }

        $kv->delete($jobId);

        return ['jobId' => $jobId, 'deleted' => true];
    }

    protected static function getDescription(): string
    {
        return 'Удалить терминальную задачу из KV-хранилища очереди.';
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
            'required' => ['jobId', 'deleted'],
            'additionalProperties' => false,
            'properties' => [
                'jobId' => [
                    'type' => 'string',
                    'description' => 'Идентификатор удалённой задачи.',
                ],
                'deleted' => [
                    'type' => 'boolean',
                    'description' => 'Признак успешного удаления.',
                ],
            ],
        ];
    }
}
