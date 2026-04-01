<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\BrowserlessQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\BrowserlessJobStatus;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\UseCase\CleanupJobDirectoryUseCase;

class PurgeCompleted extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'BrowserlessQueue.PurgeCompleted';
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
        $kv = KVNatsBucket::bucketInterface()->bucket('browserless_jobs_status');
        $entries = $kv->getAll();

        $purged = 0;
        foreach ($entries as $entry) {
            $data = json_decode($entry->value, true);
            if (!is_array($data) || empty($data['jobId'])) {
                continue;
            }
            $job = JobKVDTO::createFromArray($data);
            if ($job->status !== BrowserlessJobStatus::BROWSERLESS_COMPLETED->value) {
                continue;
            }

            CleanupJobDirectoryUseCase::handle($job);
            $kv->delete($entry->key);
            $purged++;
        }

        return ['purged' => $purged];
    }

    protected static function getDescription(): string
    {
        return 'Удалить из KV-хранилища все browserless-задачи со статусом completed и очистить временные файлы.';
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
