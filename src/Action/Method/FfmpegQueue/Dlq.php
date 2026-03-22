<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;

class Dlq extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'ffmpegQueue.dlq';
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
        $params = $methodDTO->params;
        $limit = is_array($params) ? (int)($params['limit'] ?? 20) : 20;
        $limit = max(1, min($limit, 200));

        $kv = FfmpegNatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
        $entries = $kv->getAll();

        $jobs = [];
        foreach ($entries as $entry) {
            $job = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($job)) {
                continue;
            }
            $failedStatuses = [
                FfmpegJobStatus::FAILED->value,
                FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
                FfmpegJobStatus::S3_UPLOAD_FAILED->value,
            ];
            if (!in_array($job['status'] ?? '', $failedStatuses, true)) {
                continue;
            }
            $jobs[] = $job;
        }

        $total = count($jobs);

        usort($jobs, fn(array $a, array $b) => ($b['finishedAt'] ?? '') <=> ($a['finishedAt'] ?? ''));

        $jobs = array_slice($jobs, 0, $limit);

        return [
            'jobs' => $jobs,
            'total' => $total,
        ];
    }

    protected static function getDescription(): string
    {
        return 'Получить DLQ: список задач со статусами failed, s3_download_failed, s3_upload_failed.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            OpenRpcSchema::descriptor(
                'limit',
                [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Максимальное количество failed-задач в ответе.',
                ],
                false,
                'Лимит элементов.',
            ),
        ];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['jobs', 'total'],
            'additionalProperties' => false,
            'properties' => [
                'jobs' => [
                    'type' => 'array',
                    'items' => OpenRpcSchema::ffmpegJobSchema(),
                    'description' => 'Список задач со статусом failed.',
                ],
                'total' => [
                    'type' => 'integer',
                    'description' => 'Общее количество failed-задач до применения лимита.',
                ],
            ],
        ];
    }
}
