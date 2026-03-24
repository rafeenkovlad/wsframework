<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use JsonException;
use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Exception\UseCaseException;

/**
 * compare-and-swap: гарантирует что ровно один воркер захватит задачу, даже если NATS доставил сообщение нескольким consumer-ам
 */
class ClaimJobStageUseCase
{
    /**
     * @param JobKVDTO $dto
     * @param NatsKeyValueInterface $kv
     * @param string[] $allowedStatuses
     * @param string $activeStatus
     * @return bool
     * @throws UseCaseException|JsonException
     */
    public static function handle(
        JobKVDTO $dto,
        NatsKeyValueInterface $kv,
        array $allowedStatuses,
        string $activeStatus,
    ): bool {
        $entry = $kv->getEntry($dto->jobId);
        if ($entry === null) {
            throw new UseCaseException("Job not found: {$dto->jobId}");
        }

        $jobData = JobKVDTO::createFromArray(
            json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR)
        ) ?? JobKVDTO::createWithDefaultValues();
        $currentStatus = $jobData->status;

        if (!in_array($currentStatus, $allowedStatuses, true)) {
            return false;
        }

        try {
            $kv->update(
                $dto->jobId,
                json_encode(['status' => $activeStatus, 'updatedAt' => date('c')], JSON_THROW_ON_ERROR),
                $entry->revision,
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
