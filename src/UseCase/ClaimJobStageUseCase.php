<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use JsonException;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\ClaimResult;
use WsFramework\Enum\JobType;
use WsFramework\Exception\UseCaseException;

/**
 * CAS-захват стадии джобы.
 *
 * Читает текущую revision из KV, проверяет что статус входит в allowedStatuses,
 * и атомарно переводит в activeStatus через kv->update(revision).
 * Если между чтением и записью другой воркер успел обновить запись —
 * revision не совпадёт, update бросит исключение → CONFLICT.
 * Гарантирует, что ровно один воркер захватит стадию.
 */
class ClaimJobStageUseCase
{
    /**
     * @param JobKVDTO $dto
     * @param string[] $allowedStatuses
     * @param string $activeStatus
     * @return ClaimResult
     * @throws UseCaseException|JsonException
     */
    public static function handle(
        JobKVDTO $dto,
        array $allowedStatuses,
        string $activeStatus,
        ?JobType $jobType = null,
    ): ClaimResult {
        $kv = ($jobType ?? JobType::FFMPEG)->kvBucket();
        $entry = $kv->getEntry($dto->jobId);
        if ($entry === null) {
            throw new UseCaseException("Job not found: {$dto->jobId}");
        }

        $current = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);
        $jobData = JobKVDTO::createFromArray($current) ?? JobKVDTO::createWithDefaultValues();

        if (!in_array($jobData->status, $allowedStatuses, true)) {
            return ClaimResult::STATUS_MISMATCH;
        }

        $current['status'] = $activeStatus;
        $current['updatedAt'] = date('c');

        try {
            $kv->update(
                $dto->jobId,
                json_encode($current, JSON_THROW_ON_ERROR),
                $entry->revision,
            );
        } catch (\Throwable) {
            return ClaimResult::CONFLICT;
        }

        return ClaimResult::CLAIMED;
    }
}
