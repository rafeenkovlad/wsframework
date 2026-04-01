<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\S3DownloadProcess;

use JsonException;
use Workerman\Coroutine;
use Workerman\Events\Swoole;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Dto\DefaultDTO;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\S3DownloadJobDTO;
use WsFramework\Enum\ClaimResult;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\JobType;
use WsFramework\Enum\NatsSubjectEnum;
use WsFramework\Enum\Pipeline;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\S3\S3ClientService;
use WsFramework\UseCase\ClaimJobStageUseCase;
use WsFramework\UseCase\DefineCurrentChannelUseCase;
use WsFramework\UseCase\DefineCurrentPipelineUseCase;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\RecoverStuckJobsUseCase;
use Basis\Nats\Message\Msg;
use Workerman\Worker;
use WsFramework\UseCase\ThrowableHandleUseCase;

class S3DownloadProcess extends BackgroundProcessAbstract
{
    private static S3ClientService $s3Service;

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
            ini_set('memory_limit', '3072M');
            $config = DefaultDTO::createWithDefaultValues();
            $config->pipeline = Pipeline::S3_DOWNLOAD;
            $config->channel = NatsChannel::main();
            DefineCurrentPipelineUseCase::handle($config);
            DefineCurrentChannelUseCase::handle($config);
            KVNatsBucket::main();

            static::$s3Service = new S3ClientService();

            static::recoveryJob();

            $mainCallback = function (Msg $msg) {
                echo "S3DownloadProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;

                static::checkFailed($jobId);
                static::checkClaimedStart($jobId);
                $job = static::executeDownload($jobId);
                DispatchJobByStatusUseCase::handle($job);
            };

            Coroutine::create(function () use ($mainCallback) {
                $subject = NatsSubjectEnum::S3_DOWNLOAD->getValue();
                NatsChannel::factoryListener($subject)
                    ->on($mainCallback, $subject);
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
        RecoverStuckJobsUseCase::handle(
            [FfmpegJobStatus::S3_DOWNLOADING, FfmpegJobStatus::S3_DOWNLOAD_PENDING, FfmpegJobStatus::S3_DOWNLOAD_RESTARTED],
        );
    }

    /**
     * Проверяем стартовый статус пайплайна
     * @param string $jobId
     * @return void
     * @throws JsonException
     * @throws PipelineException
     * @throws UseCaseException
     */
    private static function checkClaimedStart(string $jobId): void
    {
        $result = ClaimJobStageUseCase::handle(
            new JobKVDTO(jobId: $jobId),
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
     * @return void
     * @throws JsonException
     * @throws PipelineException
     */
    private static function checkFailed(string $jobId): void
    {
        $kv = JobType::FFMPEG->kvBucket();
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
     * @return JobKVDTO
     * @throws JsonException
     * @throws \Throwable
     */
    private static function executeDownload(string $jobId): JobKVDTO
    {
        $kv = JobType::FFMPEG->kvBucket();
        $existing = $kv->get($jobId);
        $jobKVDTO = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        try {
            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'];

            $localDir = $filesDirectory . 'tmp_jobs/' . $jobId . '/';
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $localFileName = basename($jobKVDTO->s3Key);
            $localPath = $localDir . $localFileName;

            static::$s3Service->download($jobKVDTO->s3Bucket ?? $_ENV['S3_BUCKET'], $jobKVDTO->s3Key, $localPath);

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
            static::kvMerge($jobKVDTO);

            echo "S3DownloadProcess: job {$jobId} downloaded, ready for ffmpeg\n";

            return $jobKVDTO;
        } catch (\Throwable $e) {
            echo "S3DownloadProcess: error for job {$jobId}: {$e->getMessage()}\n";
            echo "S3DownloadProcess: job {$jobId} retry {$jobKVDTO->retryCount}/{$jobKVDTO->maxRetries}\n";

            ThrowableHandleUseCase::handle($jobKVDTO, $e);

            return $jobKVDTO;
        }
    }

    protected static function setEventLoop(): void
    {
        static::$eventLoop = Swoole::class;
    }
}
