<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\NatsSubjectEnum;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\UseCase\CleanupJobDirectoryUseCase;

class PurgeAll extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'FfmpegQueue.PurgeAll';
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
        $bucketInterface = KVNatsBucket::bucketInterface();
        $kv = $bucketInterface->bucket('ffmpeg_jobs_status');
        $entries = $kv->getAll();

        $purged = 0;
        foreach ($entries as $entry) {
            $data = json_decode($entry->value, true);
            if (is_array($data) && !empty($data['jobId'])) {
                $job = JobKVDTO::createFromArray($data);
                CleanupJobDirectoryUseCase::handle($job);
            }

            $kv->purge($entry->key);
            $purged++;
        }

        $bucketInterface->purgeStream(NatsSubjectEnum::FFMPEG_JOB->stream()->getValue());

        return ['purged' => $purged];
    }

    protected static function getDescription(): string
    {
        return 'Удалить из KV-хранилища все задачи и очистить временные файлы.';
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
                    'description' => 'Количество удалённых задач.',
                ],
            ],
        ];
    }
}
