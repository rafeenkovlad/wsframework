<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use Basis\Nats\KeyValue\Entry;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use JsonException;
use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\JobType;

/**
 * Проверяет отсутствие активных задач во всех пайплайнах (FFmpeg, Browserless).
 *
 * Обходит KV-бакеты каждого JobType, десериализует JobKVDTO и считает задачи
 * с не-терминальным статусом (всё кроме completed/failed/cancelled).
 * Используется для graceful shutdown — безопасно останавливать процесс только при idle = true.
 *
 * Возвращает:
 *  - idle: bool         — true если activeJobs === 0
 *  - activeJobs: int    — общее число активных задач по всем типам
 *  - breakdown: array   — детализация по JobType (ключ "ffmpeg"|"browserless" => кол-во)
 */
final class CheckAllQueuesIdleUseCase extends AbstractUseCase
{

    private int $idleCountCheckWithTrue = 0;

    /**
     * @return array{idle: bool, activeJobs: int, breakdown: array<string, int>}
     * @throws JsonException|\Throwable
     */
    public function execute(): array
    {
        $totalActive = 0;
        $breakdown = [];

        foreach (JobType::cases() as $jobType) {
            $activeCount = $this->countActiveJobs($jobType);
            $breakdown[$jobType->value] = $activeCount;
            $totalActive += $activeCount;
        }

        if ($totalActive === 0) {
            $this->idleCountCheckWithTrue++;
        }

        if ($this->idleCountCheckWithTrue >= 3) {
            $this->shutdown();
        }

        return [
            'idle' => $totalActive === 0,
            'activeJobs' => $totalActive,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @throws JsonException|\Throwable
     */
    private function countActiveJobs(JobType $jobType): int
    {
        $activeCount = 0;
        $entries = $jobType->kvBucket()->getAll();

        /** @var Entry $entry */
        foreach ($entries as $entry) {
            $data = json_decode($entry->value, true, 512, JSON_THROW_ON_ERROR);
            $jobKVDTO = JobKVDTO::createFromArray($data) ?? JobKVDTO::createWithDefaultValues();

            if ($jobKVDTO->status === null) {
                continue;
            }

            $status = $jobType->statusClass()::tryFrom($jobKVDTO->status);

            if ($status !== null && !$status->isTerminal()) {
                $activeCount++;
            }
        }

        return $activeCount;
    }

    /**
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function shutdown(): void
    {
        $serverId = $_ENV['CLOUD_SERVER_ID'] ?? '';
        $apiUrl   = $_ENV['CLOUD_SERVER_API_URL'] ?? '';
        $apiKey   = $_ENV['CLOUD_SERVER_API_KEY'] ?? '';

        if ($serverId === '' || $apiUrl === '' || $apiKey === '') {
            return;
        }

        echo '[' . date('Y-m-d H:i:s') . '] All queues idle — sending shutdown to cloud server #' . $serverId . PHP_EOL;

        $client = new Client(['base_uri' => $apiUrl, 'http_errors' => false]);
        $client->post("/api/v2/cloud-servers/{$serverId}/actions/", [
            RequestOptions::JSON    => ['status' => -1],
            RequestOptions::HEADERS => ['Authorization' => "Bearer {$apiKey}"],
        ]);

        echo '[' . date('Y-m-d H:i:s') . '] Shutdown request sent.' . PHP_EOL;
    }

    /**
     * @param DataTransferObject|null $DTO
     * @param ...$args
     * @return array
     * @throws JsonException
     * @throws \Throwable
     */
    public static function handle(?DataTransferObject $DTO = null, ...$args): array
    {
       return static::create()
            ->execute();
    }
}
