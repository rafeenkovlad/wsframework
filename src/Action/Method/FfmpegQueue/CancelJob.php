<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Trait\FfmpegJobIdValidationTrait;

class CancelJob extends MethodAbstract
{
    use FfmpegJobIdValidationTrait;
    public static function getMethodName(): string
    {
        return 'FfmpegQueue.CancelJob';
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

        $kv = NatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
        $entry = $kv->getEntry($jobId);

        if (!$entry) {
            $methodDTO->response->errors = [['field' => 'jobId', 'message' => 'Job not found']];
            return [];
        }

        $jobData = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);
        $job = JobKVDTO::createFromArray($jobData);
        $status = $job->status ?? '';

        $cancellable = [
            FfmpegJobStatus::PENDING->value,
            FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,
            FfmpegJobStatus::S3_DOWNLOADING->value,
            FfmpegJobStatus::S3_UPLOAD_PENDING->value,
            FfmpegJobStatus::S3_UPLOADING->value,
            FfmpegJobStatus::S3_DOWNLOAD_RESTARTED->value,
            FfmpegJobStatus::PROCESSING_RESTARTED->value,
            FfmpegJobStatus::S3_UPLOAD_RESTARTED->value,
        ];
        if (!in_array($status, $cancellable, true)) {
            $methodDTO->response->errors = [[
                'field' => 'status',
                'message' => "Only pending or in-progress S3 jobs can be cancelled, current status: {$status}",
            ]];
            return [];
        }

        $jobData['status'] = FfmpegJobStatus::CANCELLED->value;
        $jobData['updatedAt'] = date('c');
        $jobData['finishedAt'] = date('c');

        try {
            $kv->update($jobId, json_encode($jobData, JSON_THROW_ON_ERROR), $entry->revision);
        } catch (\Throwable) {
            $methodDTO->response->errors = [[
                'field' => 'jobId',
                'message' => 'Revision mismatch — job state changed (possibly already picked up by consumer)',
            ]];
            return [];
        }

        return ['jobId' => $jobId, 'cancelled' => true];
    }

    protected static function getDescription(): string
    {
        return 'Отменить задачу со статусом pending, на стадии S3 или со статусом restarted.';
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
            'required' => ['jobId', 'cancelled'],
            'additionalProperties' => false,
            'properties' => [
                'jobId' => [
                    'type' => 'string',
                    'description' => 'Идентификатор отменённой задачи.',
                ],
                'cancelled' => [
                    'type' => 'boolean',
                    'description' => 'Признак успешной отмены.',
                ],
            ],
        ];
    }
}
