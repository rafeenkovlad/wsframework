<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Trait\FfmpegJobIdValidationTrait;

class RestartJob extends MethodAbstract
{
    use FfmpegJobIdValidationTrait;
    public static function getMethodName(): string
    {
        return 'ffmpegQueue.restartJob';
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

        $restartable = [
            FfmpegJobStatus::FAILED->value,
            FfmpegJobStatus::CANCELLED->value,
            FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
            FfmpegJobStatus::S3_UPLOAD_FAILED->value,
            FfmpegJobStatus::S3_DOWNLOADING->value,   // stuck
            FfmpegJobStatus::S3_UPLOADING->value,      // stuck
        ];
        if (!in_array($status, $restartable, true)) {
            $methodDTO->response->errors = [[
                'field' => 'status',
                'message' => "Only failed or cancelled jobs can be restarted, current status: {$status}",
            ]];
            return [];
        }

        // S3 download failed → перезапуск через S3 download pipeline
        if ($status === FfmpegJobStatus::S3_DOWNLOAD_FAILED->value) {
            $timestamp = date('c');
            $jobData['status'] = FfmpegJobStatus::S3_DOWNLOAD_PENDING->value;
            $jobData['retryCount'] = 0;
            $jobData['updatedAt'] = $timestamp;
            unset($jobData['error'], $jobData['errorMessage'], $jobData['finishedAt']);

            $kv->put($jobId, json_encode($jobData, JSON_THROW_ON_ERROR));

            S3NatsChannel::eventInterface()->publish(
                json_encode([
                    'jobId' => $jobId,
                    's3Bucket' => $jobData['s3Bucket'] ?? $_ENV['S3_BUCKET'],
                    's3Key' => $jobData['s3Key'] ?? '',
                ], JSON_THROW_ON_ERROR),
                S3NatsChannel::METHOD_DOWNLOAD,
            );

            return ['jobId' => $jobId, 'restarted' => true, 'status' => FfmpegJobStatus::S3_DOWNLOAD_PENDING->value];
        }

        // S3 upload failed → перезапуск через S3 upload pipeline
        if ($status === FfmpegJobStatus::S3_UPLOAD_FAILED->value) {
            $timestamp = date('c');
            $jobData['status'] = FfmpegJobStatus::S3_UPLOAD_PENDING->value;
            $jobData['retryCount'] = 0;
            $jobData['updatedAt'] = $timestamp;
            unset($jobData['error'], $jobData['errorMessage'], $jobData['finishedAt']);

            $kv->put($jobId, json_encode($jobData, JSON_THROW_ON_ERROR));

            S3NatsChannel::eventInterface()->publish(
                json_encode([
                    'jobId' => $jobId,
                    'localHlsDir' => $jobData['localHlsDir'] ?? '',
                    'playlistFile' => $jobData['playlistFile'] ?? '',
                    'segmentCount' => $jobData['segmentCount'] ?? null,
                    's3Bucket' => $jobData['s3Bucket'] ?? $_ENV['S3_BUCKET'],
                    'outputS3Prefix' => $jobData['outputS3Prefix'] ?? '',
                ], JSON_THROW_ON_ERROR),
                S3NatsChannel::METHOD_UPLOAD,
            );

            return ['jobId' => $jobId, 'restarted' => true, 'status' => FfmpegJobStatus::S3_UPLOAD_PENDING->value];
        }

        // S3 downloading (stuck) → перезапуск через S3 download pipeline
        if ($status === FfmpegJobStatus::S3_DOWNLOADING->value) {
            $timestamp = date('c');
            $jobData['status'] = FfmpegJobStatus::S3_DOWNLOAD_PENDING->value;
            $jobData['retryCount'] = 0;
            $jobData['updatedAt'] = $timestamp;
            unset($jobData['error'], $jobData['errorMessage'], $jobData['finishedAt']);

            $kv->put($jobId, json_encode($jobData, JSON_THROW_ON_ERROR));

            S3NatsChannel::eventInterface()->publish(
                json_encode([
                    'jobId' => $jobId,
                    's3Bucket' => $jobData['s3Bucket'] ?? $_ENV['S3_BUCKET'],
                    's3Key' => $jobData['s3Key'] ?? '',
                ], JSON_THROW_ON_ERROR),
                S3NatsChannel::METHOD_DOWNLOAD,
            );

            return ['jobId' => $jobId, 'restarted' => true, 'status' => FfmpegJobStatus::S3_DOWNLOAD_PENDING->value];
        }

        // S3 uploading (stuck) → перезапуск через S3 upload pipeline
        if ($status === FfmpegJobStatus::S3_UPLOADING->value) {
            $timestamp = date('c');
            $jobData['status'] = FfmpegJobStatus::S3_UPLOAD_PENDING->value;
            $jobData['retryCount'] = 0;
            $jobData['updatedAt'] = $timestamp;
            unset($jobData['error'], $jobData['errorMessage'], $jobData['finishedAt']);

            $kv->put($jobId, json_encode($jobData, JSON_THROW_ON_ERROR));

            S3NatsChannel::eventInterface()->publish(
                json_encode([
                    'jobId' => $jobId,
                    'localHlsDir' => $jobData['localHlsDir'] ?? '',
                    'playlistFile' => $jobData['playlistFile'] ?? '',
                    'segmentCount' => $jobData['segmentCount'] ?? null,
                    's3Bucket' => $jobData['s3Bucket'] ?? $_ENV['S3_BUCKET'],
                    'outputS3Prefix' => $jobData['outputS3Prefix'] ?? '',
                ], JSON_THROW_ON_ERROR),
                S3NatsChannel::METHOD_UPLOAD,
            );

            return ['jobId' => $jobId, 'restarted' => true, 'status' => FfmpegJobStatus::S3_UPLOAD_PENDING->value];
        }

        // failed / cancelled → перезапуск через ffmpeg pipeline
        $inputFile = $jobData['inputFile'] ?? null;
        if (!$inputFile) {
            $methodDTO->response->errors = [[
                'field' => 'inputFile',
                'message' => 'Job has no inputFile in KV. Re-submit the job.',
            ]];
            return [];
        }

        // Обновить KV: сбросить состояние
        $timestamp = date('c');
        $jobData['status'] = FfmpegJobStatus::PENDING->value;
        $jobData['retryCount'] = 0;
        $jobData['createdAt'] = $timestamp;
        $jobData['updatedAt'] = $timestamp;
        unset($jobData['error'], $jobData['errorMessage'], $jobData['finishedAt'], $jobData['startedAt']);

        $kv->put($jobId, json_encode($jobData, JSON_THROW_ON_ERROR));

        // Переотправить в очередь
        $payload = json_encode([
            'jobId' => $jobId,
            'inputFile' => $jobData['inputFile'],
            'outputFile' => $jobData['outputFile'] ?? null,
            'options' => $jobData['options'] ?? [],
            'priority' => $jobData['priority'] ?? 10,
        ], JSON_THROW_ON_ERROR);

        FfmpegNatsChannel::eventInterface()->publish($payload, FfmpegNatsChannel::METHOD_JOB);

        return ['jobId' => $jobId, 'restarted' => true, 'status' => FfmpegJobStatus::PENDING->value];
    }

    protected static function getDescription(): string
    {
        return 'Перезапустить задачу со статусом failed, cancelled или зависшую на стадии S3 (download/upload failed, stuck downloading/uploading).';
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
