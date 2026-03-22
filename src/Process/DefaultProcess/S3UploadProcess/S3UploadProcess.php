<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\S3UploadProcess;

use Workerman\Coroutine;
use Workerman\Events\Swoole;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsDlqChannel;
use WsFramework\Dto\FfmpegJobDTO;
use WsFramework\Dto\S3UploadDataDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\S3\S3ClientService;
use Package\NatsClient\NatsKeyValueInterface;
use Basis\Nats\Message\Msg;
use Workerman\Worker;

class S3UploadProcess extends BackgroundProcessAbstract
{
    protected static function constructor(): void
    {
    }

    protected static function setCount(): void
    {
        static::$count = (int) $_ENV['S3_UPLOAD_COUNT_PROCESS'];
    }

    protected static function setHost(): void
    {
        static::$host = $_ENV['S3_UPLOAD_PROCESS_HOST'];
    }

    public static function setProcessName(): void
    {
        static::$nameProcess = 'S3UploadProcess';
    }

    protected static function setProtocol(): void
    {
        static::$protocol = 'unix';
    }

    public static function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            S3NatsChannel::main();
            S3NatsDlqChannel::main();

            $mainCallback = function (Msg $msg) {
                echo "S3UploadProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                static::executeUpload(S3UploadDataDTO::createFromArray($data));
            };

            $dlqCallback = function (Msg $msg) {
                echo "S3UploadProcess: [DLQ] {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $originalData = $data['originalData'] ?? $data;
                static::executeUpload(S3UploadDataDTO::createFromArray($originalData));
            };

            Coroutine::create(function () use ($mainCallback) {
                S3NatsChannel::eventInterface()->on($mainCallback, S3NatsChannel::METHOD_UPLOAD);
            });

            Coroutine::create(function () use ($dlqCallback) {
                S3NatsDlqChannel::eventInterface()->on($dlqCallback, S3NatsDlqChannel::METHOD_UPLOAD_DLQ);
            });

            echo "S3UploadProcess consumer started on worker {$worker->id}\n";
        };
    }

    private static function executeUpload(S3UploadDataDTO $data): void
    {
        if (!$data->jobId) {
            throw new \RuntimeException('S3UploadProcess: missing jobId');
        }

        $kv = S3NatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');

        $existing = $kv->get($data->jobId);
        $jobData = $existing
            ? FfmpegJobDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR))
            : FfmpegJobDTO::createWithDefaultValues();

        if ($jobData->status === FfmpegJobStatus::CANCELLED->value) {
            echo "S3UploadProcess: job {$data->jobId} cancelled, skipping\n";
            return;
        }

        if ($jobData->status === FfmpegJobStatus::COMPLETED->value) {
            echo "S3UploadProcess: job {$data->jobId} already completed, skipping\n";
            return;
        }

        try {
            static::kvMerge($kv, $data->jobId, [
                'status' => FfmpegJobStatus::S3_UPLOADING->value,
            ]);

            $localHlsDir = $data->localHlsDir;
            $playlistFile = $data->playlistFile;
            $expectedSegments = $data->segmentCount;
            $bucket = $data->s3Bucket;
            $s3Prefix = $data->outputS3Prefix ?? '';

            if (!$localHlsDir || !$playlistFile) {
                throw new \RuntimeException("localHlsDir and playlistFile required for job {$data->jobId}");
            }
            if (!is_dir($localHlsDir)) {
                throw new \RuntimeException("HLS directory not found: {$localHlsDir}");
            }
            $playlistPath = $localHlsDir . $playlistFile;
            if (!is_file($playlistPath)) {
                throw new \RuntimeException("Playlist not found: {$playlistPath}");
            }
            $allFiles = glob($localHlsDir . '*');
            $hlsFiles = array_filter($allFiles ?: [], fn(string $f) =>
                in_array(pathinfo($f, PATHINFO_EXTENSION), ['m3u8', 'ts'], true)
            );
            $tsCount = count(array_filter($hlsFiles, fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'ts'));
            if ($tsCount === 0) {
                throw new \RuntimeException("No .ts segments in {$localHlsDir}");
            }
            if ($expectedSegments !== null && $tsCount !== $expectedSegments) {
                throw new \RuntimeException(
                    "Segment count mismatch for job {$data->jobId}: expected {$expectedSegments}, found {$tsCount}"
                );
            }

            $s3Service = new S3ClientService();

            foreach ($hlsFiles as $file) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $contentType = match ($ext) {
                    'm3u8' => 'application/vnd.apple.mpegurl',
                    'ts' => 'video/MP2T',
                    default => 'application/octet-stream',
                };
                $s3Service->uploadFile($file, $bucket, $s3Prefix . basename($file), $contentType);
            }

            static::kvMerge($kv, $data->jobId, [
                'status' => FfmpegJobStatus::COMPLETED->value,
                'playlistS3Key' => $s3Prefix . $playlistFile,
                'finishedAt' => date('c'),
            ]);

            // Cleanup: все файлы в директории job (HLS + оригинал .mp4)
            foreach (glob($localHlsDir . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($localHlsDir) && count(scandir($localHlsDir)) === 2) {
                rmdir($localHlsDir);
            }

            echo "S3UploadProcess: job {$data->jobId} completed\n";
        } catch (\Throwable $e) {
            echo "S3UploadProcess: error for job {$data->jobId}: {$e->getMessage()}\n";
            static::handleError(
                $kv,
                $data->jobId,
                $e,
                $jobData->maxRetries,
                $jobData->retryCount,
                $data
            );
        }
    }

    /**
     * @param NatsKeyValueInterface $kv
     * @param string $jobId
     * @param \Throwable $e
     * @param int $maxRetries
     * @param int $retryCount
     * @param S3UploadDataDTO $originalData
     * @return void
     * @throws \JsonException
     */
    private static function handleError(
        NatsKeyValueInterface $kv,
        string $jobId,
        \Throwable $e,
        int $maxRetries,
        int $retryCount,
        S3UploadDataDTO $originalData,
    ): void {

        if ($retryCount >= $maxRetries) {
            static::kvMerge($kv, $jobId, [
                'status' => FfmpegJobStatus::S3_UPLOAD_FAILED->value,
                'lastError' => $e->getMessage(),
                'finishedAt' => date('c'),
            ]);

            return;
        }

        static::kvMerge($kv, $jobId, [
            'status' => FfmpegJobStatus::S3_UPLOAD_FAILED->value,
            'retryCount' => $retryCount + 1,
            'lastError' => $e->getMessage(),
        ]);

        try {
            S3NatsDlqChannel::eventInterface()->publish(
                json_encode([
                    'jobId' => $jobId,
                    'error' => $e->getMessage(),
                    'originalData' => $originalData->toArray(),
                    'failedAt' => date('c'),
                    'stage' => 's3_upload',
                ], JSON_THROW_ON_ERROR),
                S3NatsDlqChannel::METHOD_UPLOAD_DLQ,
            );
        } catch (\Throwable $dlqError) {
            echo "S3UploadProcess: DLQ publish error: {$dlqError->getMessage()}\n";
        }

        echo "S3UploadProcess: job {$jobId} retry {$retryCount}/{$maxRetries}, sent to DLQ\n";
    }

    /**
     * @return void
     */
    protected static function setEventLoop(): void
    {
        static::$eventLoop = Swoole::class;
    }
}
