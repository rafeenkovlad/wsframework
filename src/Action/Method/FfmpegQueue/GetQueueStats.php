<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsDlqChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsDlqChannel;
use WsFramework\Dto\FfmpegJobDTO;
use WsFramework\Dto\MethodDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;

class GetQueueStats extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'ffmpegQueue.getQueueStats';
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
        /** @var FfmpegNatsChannel $channel */
        $channel = FfmpegNatsChannel::eventInterface();
        $kv = $channel->bucket('ffmpeg_jobs_status');

        // Подсчёт по статусам из KV
        $byStatus = [
            FfmpegJobStatus::S3_DOWNLOAD_PENDING->value => 0,
            FfmpegJobStatus::S3_DOWNLOADING->value => 0,
            FfmpegJobStatus::S3_DOWNLOAD_FAILED->value => 0,
            FfmpegJobStatus::PENDING->value => 0,
            FfmpegJobStatus::PROCESSING->value => 0,
            FfmpegJobStatus::COMPLETED->value => 0,
            FfmpegJobStatus::FAILED->value => 0,
            FfmpegJobStatus::CANCELLED->value => 0,
            FfmpegJobStatus::S3_UPLOAD_PENDING->value => 0,
            FfmpegJobStatus::S3_UPLOADING->value => 0,
            FfmpegJobStatus::S3_UPLOAD_FAILED->value => 0,
        ];
        $entries = $kv->getAll();
        foreach ($entries as $entry) {
            $job = FfmpegJobDTO::createFromArray(json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR));
            $status = $job->status;
            if ($status !== null && isset($byStatus[$status])) {
                $byStatus[$status]++;
            }
        }

        $totalJobs = array_sum($byStatus);

        // Stream info
        $streamInfo = ['messages' => 0, 'bytes' => 0, 'firstSeq' => 0, 'lastSeq' => 0];
        try {
            $info = $channel->getStreamInfo('ffmpeg_jobs');
            $state = $info->state ?? $info;
            $streamInfo = [
                'messages' => $state->messages ?? 0,
                'bytes' => $state->bytes ?? 0,
                'firstSeq' => $state->first_seq ?? 0,
                'lastSeq' => $state->last_seq ?? 0,
            ];
        } catch (\Throwable $e) {
            echo "GetQueueStats: stream info error: {$e->getMessage()}\n";
        }

        // DLQ stream info — per-stage
        $dlqInfo = [
            'ffmpeg'     => ['messages' => 0],
            's3Download' => ['messages' => 0],
            's3Upload'   => ['messages' => 0],
        ];

        /** @var FfmpegNatsDlqChannel $ffmpegDlqChannel */
        $ffmpegDlqChannel = FfmpegNatsDlqChannel::eventInterface();

        /** @var S3NatsDlqChannel $s3DlqChannel */
        $s3DlqChannel = S3NatsDlqChannel::eventInterface();

        $dlqStreams = [
            'ffmpeg'     => ['channel' => $ffmpegDlqChannel, 'stream' => 'ffmpeg_jobs_dlq'],
            's3Download' => ['channel' => $s3DlqChannel,     'stream' => 's3_download_dlq'],
            's3Upload'   => ['channel' => $s3DlqChannel,     'stream' => 's3_upload_dlq'],
        ];

        foreach ($dlqStreams as $key => ['channel' => $source, 'stream' => $streamName]) {
            try {
                $info = $source->getStreamInfo($streamName);
                $state = $info->state ?? $info;
                $dlqInfo[$key] = [
                    'messages' => $state->messages ?? 0,
                ];
            } catch (\Throwable $e) {
                echo "GetQueueStats: DLQ info error ({$streamName}): {$e->getMessage()}\n";
            }
        }

        // Consumer info
        $consumerInfo = ['pending' => 0, 'ackFloor' => 0];
        try {
            $info = $channel->getConsumerInfo('ffmpeg_jobs', 'ffmpeg_queue_add_job');
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
            'stream' => $streamInfo,
            'dlq' => $dlqInfo,
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
        return 'Получить агрегированную статистику: KV по статусам, JetStream stream, DLQ per-stage (ffmpeg/s3Download/s3Upload), consumer и worker-процессы.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['totalJobs', 'byStatus', 'stream', 'dlq', 'consumer', 'workers'],
            'additionalProperties' => false,
            'properties' => [
                'totalJobs' => [
                    'type' => 'integer',
                    'description' => 'Общее количество задач в KV-хранилище.',
                ],
                'byStatus' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        's3_download_pending', 's3_downloading', 's3_download_failed',
                        'pending', 'processing', 'completed', 'failed', 'cancelled',
                        's3_upload_pending', 's3_uploading', 's3_upload_failed',
                    ],
                    'properties' => [
                        's3_download_pending' => ['type' => 'integer'],
                        's3_downloading' => ['type' => 'integer'],
                        's3_download_failed' => ['type' => 'integer'],
                        'pending' => ['type' => 'integer'],
                        'processing' => ['type' => 'integer'],
                        'completed' => ['type' => 'integer'],
                        'failed' => ['type' => 'integer'],
                        'cancelled' => ['type' => 'integer'],
                        's3_upload_pending' => ['type' => 'integer'],
                        's3_uploading' => ['type' => 'integer'],
                        's3_upload_failed' => ['type' => 'integer'],
                    ],
                ],
                'stream' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['messages', 'bytes', 'firstSeq', 'lastSeq'],
                    'description' => 'Состояние основного JetStream stream очереди.',
                    'properties' => [
                        'messages' => ['type' => 'integer'],
                        'bytes' => ['type' => 'integer'],
                        'firstSeq' => ['type' => 'integer'],
                        'lastSeq' => ['type' => 'integer'],
                    ],
                ],
                'dlq' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['ffmpeg', 's3Download', 's3Upload'],
                    'description' => 'Состояние DLQ streams по стадиям.',
                    'properties' => [
                        'ffmpeg' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['messages'],
                            'properties' => [
                                'messages' => ['type' => 'integer'],
                            ],
                        ],
                        's3Download' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['messages'],
                            'properties' => [
                                'messages' => ['type' => 'integer'],
                            ],
                        ],
                        's3Upload' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['messages'],
                            'properties' => [
                                'messages' => ['type' => 'integer'],
                            ],
                        ],
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
