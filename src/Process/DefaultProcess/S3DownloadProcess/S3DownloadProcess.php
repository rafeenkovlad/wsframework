<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\S3DownloadProcess;

use JsonException;
use Workerman\Coroutine;
use Workerman\Events\Swoole;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\S3DownloadJobDTO;
use WsFramework\Enum\ClaimResult;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\S3\S3ClientService;
use WsFramework\UseCase\ClaimJobStageUseCase;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\RecoverStuckJobsUseCase;
use Package\NatsClient\NatsKeyValueInterface;
use Basis\Nats\Message\Msg;
use Workerman\Worker;
use WsFramework\UseCase\ThrowableHandleUseCase;

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
            FfmpegNatsChannel::main();
            KVNatsBucket::main();

            static::recoveryJob();

            $mainCallback = function (Msg $msg) {
                echo "S3DownloadProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;

                $kv = KVNatsBucket::eventInterface()->bucket('ffmpeg_jobs_status');

                static::checkFailed($jobId, $kv);
                static::checkClaimedStart($jobId, $kv);
                $job = static::executeDownload($jobId, $kv);
                DispatchJobByStatusUseCase::handle($job, $kv);
            };

            Coroutine::create(function () use ($mainCallback) {
                S3NatsChannel::eventInterface()->on($mainCallback, S3NatsChannel::METHOD_DOWNLOAD);
            });

            echo "S3DownloadProcess consumer started on worker {$worker->id}\n";
        };
    }

    /**
     * @return void
     * @throws PipelineException
     * @throws JsonException
     * @throws UseCaseException
     */
    private static function recoveryJob(): void
    {
        $kv = KVNatsBucket::eventInterface()->bucket('ffmpeg_jobs_status');
        RecoverStuckJobsUseCase::handle(
            $kv,
            [FfmpegJobStatus::S3_DOWNLOADING, FfmpegJobStatus::S3_DOWNLOAD_PENDING, FfmpegJobStatus::S3_DOWNLOAD_RESTARTED],
        );
    }

    /**
     * Проверяем стартовый статус пайплайна
     * @param string $jobId
     * @param NatsKeyValueInterface $kv
     * @return void
     * @throws JsonException
     * @throws PipelineException
     * @throws UseCaseException
     */
    private static function checkClaimedStart(string $jobId, NatsKeyValueInterface $kv): void
    {
        $result = ClaimJobStageUseCase::handle(
            new JobKVDTO(jobId: $jobId),
            $kv,
            allowedStatuses: [
                FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,
                FfmpegJobStatus::S3_DOWNLOAD_RESTARTED->value,
            ],
            activeStatus: FfmpegJobStatus::S3_DOWNLOADING->value,
        );

        if ($result !== ClaimResult::CLAIMED) {
            $ex = new PipelineException('S3DownloadProcess', "job {$jobId} skip — {$result->name}\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    /**
     * @param string $jobId
     * @param NatsKeyValueInterface $kv
     * @return void
     * @throws JsonException
     * @throws PipelineException
     */
    private static function checkFailed(string $jobId, NatsKeyValueInterface $kv): void
    {
        $existing = $kv->get($jobId);
        $jobKVDTO = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        if (FfmpegJobStatus::S3_DOWNLOAD_FAILED->isEq($jobKVDTO->status)) {
            $ex = new PipelineException('S3DownloadProcess', "job {$jobId} skip — {$jobKVDTO->status}\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    /**
     * @param string $jobId
     * @param NatsKeyValueInterface $kv
     * @return JobKVDTO
     * @throws JsonException
     * @throws \Throwable
     */
    private static function executeDownload(string $jobId, NatsKeyValueInterface $kv): JobKVDTO
    {

        $existing = $kv->get($jobId);
        $jobKVDTO = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        try {
            $s3Service = new S3ClientService();
            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'];

            $localDir = $filesDirectory . 'tmp_jobs/' . $jobId . '/';
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $localFileName = basename($jobKVDTO->s3Key);
            $localPath = $localDir . $localFileName;

            $s3Service->download($jobKVDTO->s3Bucket ?? $_ENV['S3_BUCKET'], $jobKVDTO->s3Key, $localPath);

            $fileSizeMb = round(filesize($localPath) / 1048576, 2);
            echo "S3DownloadProcess: job {$jobId} downloaded {$fileSizeMb}MB\n";

            $inputFile = 'tmp_jobs/' . $jobId . '/' . $localFileName;

            $jobKVDTO  = new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::PENDING->value,
                s3Download: new S3DownloadJobDTO(
                    inputFile: $inputFile,
                ),
            );
            static::kvMerge($kv, $jobKVDTO);

            echo "S3DownloadProcess: job {$jobId} downloaded, ready for ffmpeg\n";

            return $jobKVDTO;
        } catch (\Throwable $e) {
            echo "S3DownloadProcess: error for job {$jobId}: {$e->getMessage()}\n";
            echo "S3DownloadProcess: job {$jobId} retry {$jobKVDTO->retryCount}/{$jobKVDTO->maxRetries}\n";

            ThrowableHandleUseCase::handle($jobKVDTO, $kv,$e);

            return $jobKVDTO;
        }
    }

    protected static function setEventLoop(): void
    {
        static::$eventLoop = Swoole::class;
    }
}
