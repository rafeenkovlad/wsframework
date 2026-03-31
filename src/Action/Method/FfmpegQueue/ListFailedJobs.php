<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;

class ListFailedJobs extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'FfmpegQueue.ListFailedJobs';
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

        $kv = KVNatsBucket::bucketInterface()->bucket('ffmpeg_jobs_status');
        $entries = $kv->getAll();

        $jobs = [];
        foreach ($entries as $entry) {
            $data = json_decode($entry->value, true);
            if (!is_array($data) || empty($data['jobId'])) {
                continue;
            }
            $job = JobKVDTO::createFromArray($data);
            $failedStatuses = [
                FfmpegJobStatus::FAILED->value,
                FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
                FfmpegJobStatus::S3_UPLOAD_FAILED->value,
            ];
            if (!in_array($job->status ?? '', $failedStatuses, true)) {
                continue;
            }
            $jobs[] = $job;
        }

        $total = count($jobs);

        usort($jobs, fn(JobKVDTO $a, JobKVDTO $b) => ($b->finishedAt ?? '') <=> ($a->finishedAt ?? ''));

        $jobs = array_slice($jobs, 0, $limit);

        return [
            'jobs' => array_map(fn(JobKVDTO $j) => $j->toArray(), $jobs),
            'total' => $total,
        ];
    }

    protected static function getDescription(): string
    {
        return 'Получить список задач со статусами failed, s3_download_failed, s3_upload_failed.';
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
