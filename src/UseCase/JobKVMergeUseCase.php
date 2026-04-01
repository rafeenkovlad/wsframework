<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\KVMergeOptionsDTO;
use WsFramework\Enum\JobStatusInterface;
use WsFramework\Enum\JobType;
use WsFramework\Exception\UseCaseException;

class JobKVMergeUseCase extends AbstractUseCase
{
    private const CHILD_KEYS = ['s3Download', 's3Upload', 'cleanup'];

    /**
     * @param JobKVDTO $DTO
     * @param ...$args
     * @return int
     * @throws UseCaseException
     * @throws \JsonException|\Throwable
     */
    public static function handle(DataTransferObject $DTO, ...$args): int
    {
        foreach ($args as $arg) {
            if ($arg instanceof KVMergeOptionsDTO) {
                $optionsDTO = $arg;
            }
        }

        $optionsDTO ??= KVMergeOptionsDTO::createWithDefaultValues();
        if ($optionsDTO->throwable) {
            ThrowableHandleUseCase::handle($DTO, $optionsDTO->throwable);
        }
        $jobType = $optionsDTO->jobType ?? JobType::FFMPEG;

        return static::create($DTO)->merge($jobType);
    }

    /**
     * @param JobType $jobType
     * @return int
     * @throws \JsonException
     * @throws \Throwable
     */
    private function merge(JobType $jobType): int
    {
        $kv = $jobType->kvBucket();
        $jobStatusEnumClass = $jobType->statusClass();
        /** @var JobKVDTO $dto */
        $dto = $this->DTO;

        $existing = $kv->get($dto->jobId);
        if ($existing === null) {
            return $kv->put($dto->jobId, $dto->jsonEncode());
        }

        $entry = $kv->getEntry($dto->jobId);

        $current = $entry?->value
            ? (json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR) ?: [])
            : [];

        $update = array_diff_key(
            $dto->toArrayWhereNotNull(),
            array_flip([...self::CHILD_KEYS, ...JobType::allChildKeys(), 'errors']),
        );
        $update['updatedAt'] ??= date('c');

        // Restart convention
        /** @var JobStatusInterface|null $status */
        $status = $dto->status ? $jobStatusEnumClass::tryFrom($dto->status) : null;
        if ($status?->isRestartable()) {
            $current['finishedAt'] = null;
            $current['startedAt'] = null;
        }

        // Append master errors
        if (!empty($dto->errors)) {
            $update['errors'] = array_merge($current['errors'] ?? [], $dto->errors);
        }

        // Merge child DTOs
        $allChildKeys = [...self::CHILD_KEYS, ...JobType::allChildKeys()];
        foreach ($allChildKeys as $child) {
            if ($dto->$child !== null) {
                $childUpdate = $dto->$child->toArrayWhereNotNull();
                if (!empty($childUpdate['errors'])) {
                    $childUpdate['errors'] = array_merge(
                        $current[$child]['errors'] ?? [],
                        $childUpdate['errors'],
                    );
                }
                $current[$child] = array_merge($current[$child] ?? [], $childUpdate);
            }
        }

        $encoded = json_encode(
            array_merge($current, $update),
            JSON_THROW_ON_ERROR,
        );

        return $kv->update($dto->jobId, $encoded, $entry->revision);
    }
}
