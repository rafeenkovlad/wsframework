<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\ProfileUpload;

use Aws\S3\S3Client;
use PSX\OpenRPC\ContentDescriptor;
use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Dto\MethodDTO;
use WsFramework\Pool\Http\PoolHttpConnection;
use ZipArchive;

class ImportFromS3 extends MethodAbstract
{
    private const NAME_REGEX = '/^[A-Za-z0-9_\-]{1,64}$/';
    private const MAX_S3_KEY_LENGTH = 1024;
    private const MAX_S3_BUCKET_LENGTH = 63;

    public static function getMethodName(): string
    {
        return 'ProfileUpload.ImportFromS3';
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
        $params = is_array($methodDTO->params) ? $methodDTO->params : [];
        $errs = [];

        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '' || !preg_match(self::NAME_REGEX, $name)) {
            $errs[] = ['field' => 'name', 'message' => 'must match ^[A-Za-z0-9_-]{1,64}$'];
        }

        $s3Key = $params['s3_key'] ?? null;
        if (!is_string($s3Key) || $s3Key === '' || str_starts_with($s3Key, '/') || strlen($s3Key) > self::MAX_S3_KEY_LENGTH) {
            $errs[] = ['field' => 's3_key', 'message' => 'must be non-empty, not start with "/" and be ≤ 1024 chars'];
        }

        if (array_key_exists('s3_bucket', $params)) {
            $bucket = $params['s3_bucket'];
            if (!is_string($bucket) || $bucket === '' || strlen($bucket) > self::MAX_S3_BUCKET_LENGTH) {
                $errs[] = ['field' => 's3_bucket', 'message' => 'must be non-empty and ≤ 63 chars'];
            }
        }

        if (array_key_exists('overwrite', $params) && !is_bool($params['overwrite'])) {
            $errs[] = ['field' => 'overwrite', 'message' => 'must be boolean'];
        }

        if ($errs !== []) {
            $methodDTO->response->errors = $errs;
        }
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        if (!empty($methodDTO->response->errors)) {
            return [];
        }

        $params = $methodDTO->params;
        $name = $params['name'];
        $s3Key = $params['s3_key'];
        $bucket = $params['s3_bucket'] ?? ($_ENV['S3_BUCKET_APP'] ?? '');
        $overwrite = (bool)($params['overwrite'] ?? false);

        if ($bucket === '') {
            $methodDTO->response->errors = [['field' => 's3_bucket', 'message' => 'bucket not configured (S3_BUCKET_APP env empty)']];
            return [];
        }

        $target = HOME . '/storage/profiles/' . $name;
        if (is_dir($target)) {
            if (!$overwrite) {
                $methodDTO->response->errors = [['field' => 'name', 'message' => 'profile_exists: pass overwrite=true to replace']];
                return [];
            }
            static::removeDirectoryContents($target);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'profimp_');
        if ($tmp === false) {
            $methodDTO->response->errors = [['field' => 'tmp', 'message' => 'cannot allocate temp file']];
            return [];
        }
        $tmpZip = $tmp . '.zip';
        rename($tmp, $tmpZip);

        try {
            static::downloadFromS3($bucket, $s3Key, $tmpZip);
        } catch (\Throwable $e) {
            @unlink($tmpZip);
            $methodDTO->response->errors = [['field' => 's3_key', 'message' => 's3_download_failed: ' . $e->getMessage()]];
            return [];
        }

        $downloadedBytes = filesize($tmpZip);

        $zip = new ZipArchive();
        $rc = $zip->open($tmpZip, ZipArchive::CHECKCONS);
        if ($rc !== true) {
            @unlink($tmpZip);
            $methodDTO->response->errors = [['field' => 's3_key', 'message' => "invalid_zip: ZipArchive::open returned {$rc}"]];
            return [];
        }

        $targetReal = static::ensureDirectory($target);
        if ($targetReal === null) {
            $zip->close();
            @unlink($tmpZip);
            $methodDTO->response->errors = [['field' => 'name', 'message' => 'cannot create target directory']];
            return [];
        }

        $unsafe = static::findUnsafeEntry($zip, $targetReal);
        if ($unsafe !== null) {
            $zip->close();
            @unlink($tmpZip);
            $methodDTO->response->errors = [['field' => 's3_key', 'message' => "unsafe_path: zip entry escapes target ({$unsafe})"]];
            return [];
        }

        $extractedCount = $zip->numFiles;
        $extracted = $zip->extractTo($targetReal);
        $zip->close();
        @unlink($tmpZip);

        if (!$extracted) {
            $methodDTO->response->errors = [['field' => 's3_key', 'message' => 'extractTo_failed']];
            return [];
        }

        return [
            'name' => $name,
            'path' => 'storage/profiles/' . $name,
            'files' => $extractedCount,
            'bytes' => $downloadedBytes !== false ? $downloadedBytes : 0,
        ];
    }

    private static function downloadFromS3(string $bucket, string $key, string $localPath): void
    {
        $client = static::buildS3Client();

        $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        $expected = isset($head['ContentLength']) ? (int)$head['ContentLength'] : null;
        if ($expected === null) {
            throw new \RuntimeException("missing ContentLength for s3://{$bucket}/{$key}");
        }

        $client->getObject([
            'Bucket' => $bucket,
            'Key'    => $key,
            '@http'  => ['sink' => $localPath],
        ]);

        clearstatcache(true, $localPath);
        $actual = filesize($localPath);
        if ($actual === false || $actual !== $expected) {
            if (file_exists($localPath)) {
                @unlink($localPath);
            }
            throw new \RuntimeException("size mismatch for s3://{$bucket}/{$key} (expected {$expected}, got " . var_export($actual, true) . ')');
        }
    }

    private static function buildS3Client(): S3Client
    {
        $config = [
            'region' => $_ENV['S3_REGION'] ?? 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key' => $_ENV['S3_ACCESS_KEY'] ?? '',
                'secret' => $_ENV['S3_SECRET_KEY'] ?? '',
            ],
            'http' => [
                'timeout' => (int)($_ENV['S3_HTTP_TIMEOUT'] ?? 600),
                'connect_timeout' => (int)($_ENV['S3_HTTP_CONNECT_TIMEOUT'] ?? 10),
            ],
        ];
        $endpoint = $_ENV['S3_ENDPOINT'] ?? '';
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }
        return new S3Client($config);
    }

    private static function ensureDirectory(string $target): ?string
    {
        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            return null;
        }
        $real = realpath($target);
        return $real !== false ? $real : null;
    }

    private static function findUnsafeEntry(ZipArchive $zip, string $targetReal): ?string
    {
        $targetRealNorm = rtrim($targetReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || $entry === '') {
                return '(empty)';
            }
            if (str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry)) {
                return $entry;
            }
            $candidate = $targetRealNorm . str_replace(['\\'], '/', $entry);
            $normalized = static::normalizePath($candidate);
            if ($normalized === null || !str_starts_with($normalized, $targetRealNorm)) {
                return $entry;
            }
        }
        return null;
    }

    private static function normalizePath(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (array_pop($parts) === null) {
                    return null;
                }
                continue;
            }
            $parts[] = $segment;
        }
        $prefix = str_starts_with($path, '/') ? '/' : '';
        return $prefix . implode('/', $parts);
    }

    private static function removeDirectoryContents(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    protected static function getDescription(): string
    {
        return 'Скачать .zip-архив профиля из S3 и распаковать в storage/profiles/<name>/.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            static::descriptor('name', [
                'type' => 'string',
                'pattern' => '^[A-Za-z0-9_\-]{1,64}$',
                'description' => 'Имя целевой папки профиля в storage/profiles/.',
            ], true),
            static::descriptor('s3_key', [
                'type' => 'string',
                'maxLength' => 1024,
                'description' => 'S3-ключ архива (без ведущего "/").',
            ], true),
            static::descriptor('s3_bucket', [
                'type' => 'string',
                'maxLength' => 63,
                'description' => 'Имя S3-бакета. По умолчанию — $_ENV[S3_BUCKET].',
            ], false),
            static::descriptor('overwrite', [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Перезаписать целевую папку, если она уже существует.',
            ], false),
        ];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'path', 'files', 'bytes'],
            'additionalProperties' => false,
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Имя распакованного профиля.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Относительный путь к распакованной папке.',
                ],
                'files' => [
                    'type' => 'integer',
                    'description' => 'Количество записей, извлечённых из zip.',
                ],
                'bytes' => [
                    'type' => 'integer',
                    'description' => 'Размер скачанного архива в байтах.',
                ],
            ],
        ];
    }

    private static function descriptor(string $name, array $schema, bool $required): ContentDescriptor
    {
        $d = new ContentDescriptor();
        $d->setName($name);
        $d->setRequired($required);
        $d->setSchema($schema);
        return $d;
    }
}
