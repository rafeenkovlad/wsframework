<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Exception\UseCaseException;

class JobKVMergeUseCase extends AbstractUseCase
{
    private const CHILD_KEYS = ['s3Download', 'ffmpegJob', 's3Upload', 'cleanup'];

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
            if ($arg instanceof \Throwable) {
                ThrowableHandleUseCase::handle($DTO, $arg);
            }
        }

        return static::create($DTO)->merge();
    }

    private function merge(): int
    {
        $kv = GetKVInterfaceUseCase::handle();
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
            array_flip([...self::CHILD_KEYS, 'errors']),
        );
        $update['updatedAt'] ??= date('c');

        // Restart convention
        $status = $dto->status ? FfmpegJobStatus::fromString($dto->status) : null;
        if ($status?->isRestartable()) {
            $current['finishedAt'] = null;
            $current['startedAt'] = null;
        }

        // Append master errors
        if (!empty($dto->errors)) {
            $update['errors'] = array_merge($current['errors'] ?? [], $dto->errors);
        }

        // Merge child DTOs
        foreach (self::CHILD_KEYS as $child) {
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
