<?php

declare(strict_types=1);

namespace WsFramework\Service\Browserless;

use RuntimeException;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;

class ProfilePoolManager
{
    private const BUCKET_NAME = 'browserless_profiles_pool';
    private const MAX_WAIT_SECONDS = 60;
    private const RETRY_DELAY_MS = 500;

    public function __construct(
        private readonly string $profilesDir,
    ) {}

    /**
     * Захватить свободный профиль из пула.
     * Если все заняты — ждать освобождения.
     */
    public function claimProfile(string $fingerprintBase, string $jobId): string
    {
        echo "ProfilePool: job {$jobId} requesting profile for fingerprint {$fingerprintBase}\n";

        $kv = KVNatsBucket::bucketInterface()->bucket(self::BUCKET_NAME);
        $startTime = time();

        while (true) {
            // Получить все профили для fingerprint
            $profiles = $this->getProfilesForFingerprint($kv, $fingerprintBase);

            echo "ProfilePool: job {$jobId} found " . count($profiles) . " profiles for {$fingerprintBase}\n";

            if (empty($profiles)) {
                throw new RuntimeException("No profiles found for fingerprint: {$fingerprintBase}");
            }

            // Попытаться захватить свободный профиль
            foreach ($profiles as $profileName => $entry) {
                $data = json_decode($entry->value, true);

                if ($data['status'] === 'free') {
                    // Попытка CAS-захвата
                    $data['status'] = 'busy';
                    $data['jobId'] = $jobId;
                    $data['claimedAt'] = date('c');

                    try {
                        $kv->update($profileName, json_encode($data), $entry->revision);
                        echo "ProfilePool: job {$jobId} claimed profile {$profileName}\n";

                        // Очистить поврежденный SingletonLock если он существует
                        $this->cleanupCorruptedSingletonLock($profileName);

                        return $profileName;
                    } catch (\Throwable) {
                        // Другой воркер захватил раньше, продолжить поиск
                        continue;
                    }
                }
            }

            // Все профили заняты — проверить timeout
            $elapsed = time() - $startTime;
            if ($elapsed >= self::MAX_WAIT_SECONDS) {
                throw new RuntimeException("Timeout waiting for free profile: {$fingerprintBase} (waited {$elapsed}s)");
            }

            // Подождать и повторить
            if ($elapsed % 10 === 0 && $elapsed > 0) {
                echo "ProfilePool: job {$jobId} waiting for free profile {$fingerprintBase} ({$elapsed}s elapsed)\n";
            }
            usleep(self::RETRY_DELAY_MS * 1000);
        }
    }

    /**
     * Освободить профиль после использования.
     */
    public function releaseProfile(string $profileName, string $jobId): void
    {
        $kv = KVNatsBucket::bucketInterface()->bucket(self::BUCKET_NAME);

        $maxRetries = 5;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $entry = $kv->getEntry($profileName);
                if ($entry === null) {
                    echo "ProfilePool: profile {$profileName} not found in KV\n";
                    return;
                }

                $data = json_decode($entry->value, true);

                // Проверка: освобождаем только если профиль занят этим job'ом
                if ($data['jobId'] !== $jobId) {
                    echo "ProfilePool: profile {$profileName} is not owned by job {$jobId} (owner: {$data['jobId']})\n";
                    return;
                }

                $data['status'] = 'free';
                $data['jobId'] = null;
                $data['releasedAt'] = date('c');

                $kv->update($profileName, json_encode($data), $entry->revision);
                echo "ProfilePool: job {$jobId} released profile {$profileName}\n";
                return;

            } catch (\Throwable $e) {
                if ($i === $maxRetries - 1) {
                    throw $e;
                }
                usleep(100000);  // 100ms
            }
        }
    }

    /**
     * Инициализировать пул профилей при старте worker.
     */
    public function initializePool(): void
    {
        echo "ProfilePool: initializing pool from directory: {$this->profilesDir}\n";

        $kv = KVNatsBucket::bucketInterface()->bucket(self::BUCKET_NAME);

        $entries = scandir($this->profilesDir);
        if ($entries === false) {
            throw new RuntimeException("Failed to scan profiles directory: {$this->profilesDir}");
        }

        $initialized = 0;
        $recovered = 0;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->profilesDir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }

            echo "ProfilePool: processing profile: {$entry}\n";

            $existing = $kv->get($entry);
            if ($existing === null) {
                // Создать новую запись
                $data = [
                    'status' => 'free',
                    'jobId' => null,
                    'claimedAt' => null,
                    'releasedAt' => null,
                    'createdAt' => date('c'),
                ];
                $kv->put($entry, json_encode($data));
                $initialized++;
                echo "ProfilePool: initialized new profile: {$entry}\n";
            } else {
                // Сбросить статус в "free" (на случай краша)
                $data = json_decode($existing, true);
                if ($data['status'] === 'busy') {
                    $entry_obj = $kv->getEntry($entry);
                    $data['status'] = 'free';
                    $data['jobId'] = null;
                    $data['recoveredAt'] = date('c');
                    try {
                        $kv->update($entry, json_encode($data), $entry_obj->revision);
                        $recovered++;
                        echo "ProfilePool: recovered stuck profile: {$entry}\n";
                    } catch (\Throwable) {
                        // Ignore conflicts
                    }
                }
            }
        }

        echo "ProfilePool: initialized {$initialized} new profiles, recovered {$recovered} stuck profiles\n";
    }

    /**
     * Получить путь к профилю на диске.
     */
    public function getProfilePath(string $profileName): string
    {
        $path = $this->profilesDir . '/' . $profileName;
        if (!is_dir($path)) {
            throw new RuntimeException("Profile directory not found: {$path}");
        }
        return $path;
    }

    /**
     * Получить все профили для fingerprint (chrome_win10_1, chrome_win10_2, ...).
     */
    private function getProfilesForFingerprint($kv, string $fingerprintBase): array
    {
        $allEntries = $kv->getAll();
        $profiles = [];

        foreach ($allEntries as $entry) {
            // Проверить, начинается ли имя профиля с fingerprintBase
            if (str_starts_with($entry->key, $fingerprintBase)) {
                $profiles[$entry->key] = $entry;
            }
        }

        return $profiles;
    }

    /**
     * Очистить поврежденный SingletonLock если он существует.
     * Chrome создает символическую ссылку SingletonLock для предотвращения
     * одновременного использования профиля. Если процесс крашится, ссылка
     * может остаться в поврежденном состоянии.
     */
    private function cleanupCorruptedSingletonLock(string $profileName): void
    {
        $profilePath = $this->getProfilePath($profileName);
        $lockPath = $profilePath . '/SingletonLock';

        if (!file_exists($lockPath)) {
            return; // Файл не существует, все ОК
        }

        // Проверить, является ли это символической ссылкой
        if (is_link($lockPath)) {
            // Попытаться прочитать ссылку
            $target = @readlink($lockPath);
            if ($target === false) {
                // readlink() failed — ссылка повреждена
                echo "ProfilePool: detected corrupted SingletonLock in {$profileName}, removing\n";
                @unlink($lockPath);
            }
        } else {
            // Это обычный файл, а не символическая ссылка — тоже неправильно
            echo "ProfilePool: detected invalid SingletonLock (not a symlink) in {$profileName}, removing\n";
            @unlink($lockPath);
        }
    }
}
