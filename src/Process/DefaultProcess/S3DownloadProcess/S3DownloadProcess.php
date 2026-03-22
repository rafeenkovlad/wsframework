<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\S3DownloadProcess;

use Workerman\Coroutine;
use Workerman\Coroutine\Channel;
use Workerman\Events\Swoole;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsDlqChannel;
use WsFramework\Dto\FfmpegJobDTO;
use WsFramework\Dto\S3DownloadDataDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\S3\S3ClientService;
use Package\NatsClient\NatsKeyValueInterface;
use Basis\Nats\Message\Msg;
use Workerman\Worker;

class S3DownloadProcess extends BackgroundProcessAbstract
{
    protected static function constructor(): void
    {
    }

    protected static function setCount(): void
    {
        static::$count = (int) $_ENV['S3_DOWNLOAD_COUNT_PROCESS'];
    }

    public static function setProcessName(): void
    {
        static::$nameProcess = 'S3DownloadProcess';
    }

    protected static function setProtocol(): void
    {
        static::$protocol = 'unix';
    }

    protected static function setHost(): void
    {
        static::$host = $_ENV['S3_DOWNLOAD_PROCESS_HOST'];
    }

    public static function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            ini_set('memory_limit', '3G');
            S3NatsChannel::main();
            S3NatsDlqChannel::main();
            FfmpegNatsChannel::main();

            $semaphore = new Channel(1);

            $mainCallback = function (Msg $msg) use ($semaphore) {
                echo "S3DownloadProcess: received message: {$msg->payload->body}\n";
                $semaphore->push(true);
                try {
                    $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                    static::executeDownload(S3DownloadDataDTO::createFromArray($data));
                } finally {
                    $semaphore->pop();
                }
            };

            $dlqCallback = function (Msg $msg) use ($semaphore) {
                echo "S3DownloadProcess: [DLQ] {$msg->payload->body}\n";
                $semaphore->push(true);
                try {
                    $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                    $originalData = $data['originalData'] ?? $data;
                    static::executeDownload(S3DownloadDataDTO::createFromArray($originalData));
                } finally {
                    $semaphore->pop();
                }
            };

            Coroutine::create(function () use ($mainCallback) {
                S3NatsChannel::eventInterface()->on(
                    $mainCallback,
                    S3NatsChannel::METHOD_DOWNLOAD
                );
            });

            Coroutine::create(function () use ($dlqCallback) {
                S3NatsDlqChannel::eventInterface()->on(
                    $dlqCallback,
                    S3NatsDlqChannel::METHOD_DOWNLOAD_DLQ
                );
            });


            echo "S3DownloadProcess consumer started on worker {$worker->id}\n";
        };
    }

    private static function executeDownload(S3DownloadDataDTO $data): void
    {
        if (!$data->jobId) {
            throw new \RuntimeException('S3DownloadProcess: missing jobId');
        }

        $kv = S3NatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');

        $existing = $kv->get($data->jobId);
        $jobData = $existing
            ? FfmpegJobDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR))
            : FfmpegJobDTO::createWithDefaultValues();

        if ($jobData->status === FfmpegJobStatus::CANCELLED->value) {
            echo "S3DownloadProcess: job {$data->jobId} cancelled, skipping\n";
            return;
        }

        $skipStatuses = [
            FfmpegJobStatus::PENDING->value,
            FfmpegJobStatus::PROCESSING->value,
            FfmpegJobStatus::FAILED->value,
            FfmpegJobStatus::COMPLETED->value,

            FfmpegJobStatus::S3_UPLOAD_PENDING->value,
            FfmpegJobStatus::S3_UPLOADING->value,
            FfmpegJobStatus::S3_UPLOAD_FAILED->value,
        ];
        if (in_array($jobData->status, $skipStatuses, true)) {
            echo "S3DownloadProcess: job {$data->jobId} already past download stage ({$jobData->status}), skipping\n";
            return;
        }

        try {
            static::kvMerge($kv, $data->jobId, ['status' => FfmpegJobStatus::S3_DOWNLOADING->value]);
            $s3Service = new S3ClientService();
            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'];

            $localDir = $filesDirectory . 'tmp_jobs/' . $data->jobId . '/';
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $localFileName = basename($data->s3Key);
            $localPath = $localDir . $localFileName;

            $s3Service->download($data->s3Bucket, $data->s3Key, $localPath);

            $fileSizeMb = round(filesize($localPath) / 1048576, 2);
            echo "S3DownloadProcess: job {$data->jobId} downloaded {$fileSizeMb}MB\n";

            $inputFile = 'tmp_jobs/' . $data->jobId . '/' . $localFileName;

            static::kvMerge($kv, $data->jobId, [
                'status' => FfmpegJobStatus::PENDING->value,
                'inputFile' => $inputFile,
            ]);

            FfmpegNatsChannel::eventInterface()->publish(
                json_encode([
                    'jobId' => $data->jobId,
                    'inputFile' => $inputFile,
                ], JSON_THROW_ON_ERROR),
                FfmpegNatsChannel::METHOD_JOB,
            );

            echo "S3DownloadProcess: job {$data->jobId} downloaded, published to ffmpeg_jobs\n";
        } catch (\Throwable $e) {
            echo "S3DownloadProcess: error for job {$data->jobId}: {$e->getMessage()}\n";
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
     * @param S3DownloadDataDTO $originalData
     * @return void
     * @throws \JsonException
     */
    private static function handleError(
        NatsKeyValueInterface $kv,
        string $jobId,
        \Throwable $e,
        int $maxRetries,
        int $retryCount,
        S3DownloadDataDTO $originalData,
    ): void {

        if ($retryCount >= $maxRetries) {
            static::kvMerge($kv, $jobId, [
                'status' => FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
                'lastError' => $e->getMessage(),
                'finishedAt' => date('c'),
            ]);

            return;
        }

        static::kvMerge($kv, $jobId, [
            'status' => FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
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
                    'stage' => 's3_download',
                ], JSON_THROW_ON_ERROR),
                S3NatsDlqChannel::METHOD_DOWNLOAD_DLQ,
            );
        } catch (\Throwable $dlqError) {
            echo "S3DownloadProcess: DLQ publish error: {$dlqError->getMessage()}\n";
        }

        echo "S3DownloadProcess: job {$jobId} retry {$retryCount}/{$maxRetries}, sent to DLQ\n";
    }

    /**
     * @return void
     */
    protected static function setEventLoop(): void
    {
        static::$eventLoop = Swoole::class;
    }
}
