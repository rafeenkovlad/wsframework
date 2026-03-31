<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\MethodDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\NatsStreamEnum;
use WsFramework\Enum\NatsSubject;
use WsFramework\Enum\NatsSubjectEnum;
use WsFramework\Pool\Http\PoolHttpConnection;

class GetQueueStats extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'FfmpegQueue.GetQueueStats';
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
        /** @var NatsChannel $natsChannel */
        $natsChannel = NatsChannel::eventInterface();

        $kv = KVNatsBucket::bucketInterface()->bucket('ffmpeg_jobs_status');

        // Count by status from KV
        $byStatus = [];
        foreach (FfmpegJobStatus::cases() as $case) {
            $byStatus[$case->value] = 0;
        }

        $entries = $kv->getAll();
        foreach ($entries as $entry) {
            $data = json_decode($entry->value, true);
            if (!is_array($data) || empty($data['jobId'])) {
                continue;
            }
            $job = JobKVDTO::createFromArray($data);
            $status = $job->status;
            if ($status !== null && isset($byStatus[$status])) {
                $byStatus[$status]++;
            }
        }

        $totalJobs = array_sum($byStatus);

        // Per-stage stream info
        $streamDefault = ['messages' => 0, 'bytes' => 0, 'firstSeq' => 0, 'lastSeq' => 0];
        $streams = [];

        $streamMap = [
            's3Download' => ['stream' => 's3_download'],
            'ffmpegJobs' => ['stream' => 'ffmpeg_jobs'],
            's3Upload'   => ['stream' => 's3_upload'],
        ];

        foreach ($streamMap as $key => ['stream' => $streamName]) {
            try {
                $info = $natsChannel->getStreamInfo($streamName);
                $state = $info->state ?? $info;
                $streams[$key] = [
                    'messages' => $state->messages ?? 0,
                    'bytes' => $state->bytes ?? 0,
                    'firstSeq' => $state->first_seq ?? 0,
                    'lastSeq' => $state->last_seq ?? 0,
                ];
            } catch (\Throwable $e) {
                echo "GetQueueStats: stream info error ({$streamName}): {$e->getMessage()}\n";
                $streams[$key] = $streamDefault;
            }
        }

        // Consumer info
        $consumerInfo = ['pending' => 0, 'ackFloor' => 0];
        try {
            $info = $natsChannel->getConsumerInfo(
                NatsStreamEnum::FFMPEG_JOB->getValue(),
                $natsChannel->getConsumerName(NatsSubjectEnum::FFMPEG_JOB->getValue())
            );
            $consumerInfo = [
                'pending' => $info->num_pending ?? 0,
                'ackFloor' => $info->num_ack_floor ?? $info->ack_floor->stream_seq ?? 0,
            ];
        } catch (\Throwable $e) {
            echo "GetQueueStats: consumer info error: {$e->getMessage()}\n";
        }

        // Workers info
        $configured = (int)($_ENV['FFMPEG_QUEUE_COUNT_PROCESS'] ?? 1);
        $busy = $byStatus[FfmpegJobStatus::PROCESSING->value];

        return [
            'totalJobs' => $totalJobs,
            'byStatus' => $byStatus,
            'streams' => $streams,
            'consumer' => $consumerInfo,
            'workers' => [
                'configured' => $configured,
                'busy' => $busy,
                'idle' => max(0, $configured - $busy),
            ],
        ];
    }

    protected static function getDescription(): string
    {
        return 'Получить агрегированную статистику: KV по статусам, JetStream streams per-stage, consumer и worker-процессы.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [];
    }

    protected static function getResult(): ?array
    {
        $streamSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['messages', 'bytes', 'firstSeq', 'lastSeq'],
            'properties' => [
                'messages' => ['type' => 'integer'],
                'bytes' => ['type' => 'integer'],
                'firstSeq' => ['type' => 'integer'],
                'lastSeq' => ['type' => 'integer'],
            ],
        ];

        $statusProps = [];
        $statusRequired = [];
        foreach (FfmpegJobStatus::cases() as $case) {
            $statusProps[$case->value] = ['type' => 'integer'];
            $statusRequired[] = $case->value;
        }

        return [
            'type' => 'object',
            'required' => ['totalJobs', 'byStatus', 'streams', 'consumer', 'workers'],
            'additionalProperties' => false,
            'properties' => [
                'totalJobs' => [
                    'type' => 'integer',
                    'description' => 'Общее количество задач в KV-хранилище.',
                ],
                'byStatus' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $statusRequired,
                    'properties' => $statusProps,
                ],
                'streams' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['s3Download', 'ffmpegJobs', 's3Upload'],
                    'description' => 'Состояние JetStream streams по стадиям.',
                    'properties' => [
                        's3Download' => $streamSchema,
                        'ffmpegJobs' => $streamSchema,
                        's3Upload'   => $streamSchema,
                    ],
                ],
                'consumer' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['pending', 'ackFloor'],
                    'description' => 'Состояние durable consumer worker-процесса.',
                    'properties' => [
                        'pending' => ['type' => 'integer'],
                        'ackFloor' => ['type' => 'integer'],
                    ],
                ],
                'workers' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['configured', 'busy', 'idle'],
                    'description' => 'Сводка по сконфигурированным worker-процессам.',
                    'properties' => [
                        'configured' => ['type' => 'integer'],
                        'busy' => ['type' => 'integer'],
                        'idle' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }
}
