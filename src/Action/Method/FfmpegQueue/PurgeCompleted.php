<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;

class PurgeCompleted extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'ffmpegQueue.purgeCompleted';
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
        $kv = FfmpegNatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
        $entries = $kv->getAll();

        $purged = 0;
        foreach ($entries as $entry) {
            $job = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($job)) {
                continue;
            }
            if (($job['status'] ?? '') === FfmpegJobStatus::COMPLETED->value) {
                $kv->delete($entry->key);
                $purged++;
            }
        }

        return ['purged' => $purged];
    }

    protected static function getDescription(): string
    {
        return 'Удалить из KV-хранилища все задачи со статусом completed.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['purged'],
            'additionalProperties' => false,
            'properties' => [
                'purged' => [
                    'type' => 'integer',
                    'description' => 'Количество удалённых completed-задач.',
                ],
            ],
        ];
    }
}
