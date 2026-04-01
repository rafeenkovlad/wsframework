<?php

declare(strict_types=1);

namespace WsFramework\Service\Browserless;

use RuntimeException;
use SQLite3;

class CookieStorageProvider
{
    private readonly string $profilesDir;
    private readonly string $tmpDir;

    public function __construct(?string $profilesDir = null, ?string $tmpDir = null)
    {
        $this->profilesDir = rtrim($profilesDir ?? $_ENV['BROWSERLESS_PROFILES_DIR'] ?? '/var/www/html/storage/profiles', '/') . '/';
        $this->tmpDir = rtrim($tmpDir ?? '/tmp/browserless-jobs', '/') . '/';
    }

    /**
     * Read cookies from Chrome SQLite profile, decrypting v10 encrypted values.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getCookies(string $fingerprint): ?array
    {
        $dbPath = $this->profilesDir . $fingerprint . '/Default/Cookies';
        if (!is_file($dbPath)) {
            return null;
        }

        $decryptor = new ChromeCookieDecryptor();

        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(3000);

        try {
            $stmt = $db->prepare(
                'SELECT host_key, name, value, encrypted_value, path, expires_utc,
                        is_secure, is_httponly, samesite, is_persistent
                 FROM cookies',
            );
            $result = $stmt->execute();

            $cookies = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $cookieValue = $row['value'];
                if ($cookieValue === '' && $row['encrypted_value'] !== '') {
                    $cookieValue = $decryptor->decrypt($row['encrypted_value']);
                }

                $cookie = [
                    'name'     => $row['name'],
                    'value'    => $cookieValue,
                    'domain'   => $row['host_key'],
                    'path'     => $row['path'],
                    'secure'   => (bool) $row['is_secure'],
                    'httpOnly' => (bool) $row['is_httponly'],
                    'session'  => !((bool) $row['is_persistent']),
                ];

                if ($row['expires_utc'] > 0) {
                    $cookie['expires'] = ($row['expires_utc'] / 1_000_000) - 11_644_473_600;
                }

                $cookie['sameSite'] = match ((int) $row['samesite']) {
                    0       => 'None',
                    1       => 'Lax',
                    2       => 'Strict',
                    default => 'Lax',
                };

                $cookies[] = $cookie;
            }

            return $cookies;
        } finally {
            $db->close();
        }
    }

    /**
     * Delete all cookies from Chrome SQLite profile.
     */
    public function deleteCookies(string $fingerprint): void
    {
        $dbPath = $this->profilesDir . $fingerprint . '/Default/Cookies';
        if (!is_file($dbPath)) {
            return;
        }

        $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
        $db->busyTimeout(3000);

        try {
            $db->exec('DELETE FROM cookies');
        } finally {
            $db->close();
        }
    }

    /**
     * @return array<int, array{name: string, cookieCount: int, updatedAt: string|null}>
     */
    public function listFingerprints(): array
    {
        if (!is_dir($this->profilesDir)) {
            return [];
        }

        $result = [];
        $entries = scandir($this->profilesDir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dbPath = $this->profilesDir . $entry . '/Default/Cookies';
            if (!is_file($dbPath)) {
                continue;
            }

            $cookieCount = 0;
            try {
                $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
                $db->busyTimeout(3000);
                $row = $db->querySingle('SELECT COUNT(*) as cnt FROM cookies', true);
                $cookieCount = (int) ($row['cnt'] ?? 0);
                $db->close();
            } catch (\Exception) {
                // SQLite might be locked or corrupt
            }

            $mtime = filemtime($dbPath);

            $result[] = [
                'name'        => $entry,
                'cookieCount' => $cookieCount,
                'updatedAt'   => $mtime !== false ? date('c', $mtime) : null,
            ];
        }

        return $result;
    }

    public function profileExists(string $fingerprint): bool
    {
        return is_dir($this->profilesDir . $fingerprint);
    }

    public function getProfilePath(string $fingerprint): string
    {
        return $this->profilesDir . $fingerprint;
    }

    /**
     * Copy master profile to tmp directory for a job.
     *
     * @return string Path to the tmp profile directory
     */
    public function copyProfileToTmp(string $fingerprint, string $jobId): string
    {
        $source = $this->profilesDir . $fingerprint;
        $dest = $this->tmpDir . $jobId;

        if (!is_dir($source)) {
            throw new RuntimeException("Profile directory not found: {$source}");
        }

        $this->ensureDirectory($dest);
        $this->recursiveCopy($source, $dest);

        return $dest;
    }

    public function cleanupTmpProfile(string $jobId): void
    {
        $dir = $this->tmpDir . $jobId;
        if (is_dir($dir)) {
            $this->recursiveDelete($dir);
        }
    }

    public function getProfilesDir(): string
    {
        return $this->profilesDir;
    }

    public function getTmpDir(): string
    {
        return $this->tmpDir;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }
    }

    private function recursiveCopy(string $source, string $dest): void
    {
        $dir = opendir($source);
        if ($dir === false) {
            throw new RuntimeException("Failed to open directory: {$source}");
        }

        $this->ensureDirectory($dest);

        while (($entry = readdir($dir)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath = $source . '/' . $entry;
            $dstPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                $this->recursiveCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
    }

    private function recursiveDelete(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
