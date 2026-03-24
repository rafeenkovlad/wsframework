<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Exception\UseCaseException;

class JobKVMergeUseCase extends AbstractUseCase
{
    private const RESTART_STATUSES = [
        'pending',
        's3_download_pending',
        's3_upload_pending',
        's3_download_restarted',
        'processing_restarted',
        's3_upload_restarted',
    ];

    private const MASTER_FIELDS = [
        'jobId', 'status', 's3Key', 's3Bucket', 'outputS3Prefix',
        'retryCount', 'maxRetries', 'priority',
        'startedAt', 'finishedAt', 'createdAt', 'updatedAt',
    ];

    private const CHILD_KEYS = ['s3Download', 'ffmpegJob', 's3Upload', 'cleanup'];

    /**
     * @param DataTransferObject $DTO
     * @param ...$args
     * @return void
     * @throws UseCaseException
     * @throws \JsonException
     */
    public static function handle(DataTransferObject $DTO, ...$args): void
    {
        if (empty($args)) {
            throw new UseCaseException('No kv provided');
        }

        /** @var NatsKeyValueInterface $kv */
        [$kv] = $args;
        static::create($DTO)->merge($kv);
    }

    private function merge(?NatsKeyValueInterface $kv): void
    {
        /** @var JobKVDTO $dto */
        $dto = $this->DTO;

        $existing = $kv->get($dto->jobId);
        $current = $existing
            ? (json_decode($existing, true, 512, JSON_THROW_ON_ERROR) ?: [])
            : [];

        $update = $this->buildMasterUpdate();
        $update['updatedAt'] ??= date('c');

        // Restart convention
        if (in_array($dto->status, self::RESTART_STATUSES, true)) {
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
                // Append child errors
                if (!empty($childUpdate['errors'])) {
                    $childUpdate['errors'] = array_merge(
                        $current[$child]['errors'] ?? [],
                        $childUpdate['errors'],
                    );
                }
                $current[$child] = array_merge($current[$child] ?? [], $childUpdate);
            }
        }

        $kv->put($dto->jobId, json_encode(
            array_merge($current, $update),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function buildMasterUpdate(): array
    {
        /** @var JobKVDTO $dto */
        $dto = $this->DTO;
        $update = [];

        foreach (self::MASTER_FIELDS as $field) {
            if ($dto->$field !== null) {
                $update[$field] = $dto->$field;
            }
        }

        return $update;
    }
}
