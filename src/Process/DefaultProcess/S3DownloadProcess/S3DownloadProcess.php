<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\S3DownloadProcess;

use JsonException;
use Workerman\Coroutine;
use Workerman\Events\Swoole;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\S3DownloadJobDTO;
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

            //static::recoveryJob();

            $mainCallback = function (Msg $msg) {
                echo "S3DownloadProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;

                $kv = S3NatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
                try {
                    static::executeDownload($jobId, $kv);
                } catch (PipelineException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    static::kvMerge($kv, new JobKVDTO(
                        jobId: $jobId,
                        status: FfmpegJobStatus::S3_DOWNLOAD_RESTARTED->value,
                        s3Download: new S3DownloadJobDTO(
                            errors: [['message' => $e->getMessage(), 'at' => date('c')]],
                        ),
                    ));
                    DispatchJobByStatusUseCase::handle($jobId, $kv);
                }
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
        $kv = S3NatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
        RecoverStuckJobsUseCase::handle($kv, [FfmpegJobStatus::S3_DOWNLOADING]);
    }

    private static function executeDownload(string $jobId, NatsKeyValueInterface $kv): void
    {
        $claimed = ClaimJobStageUseCase::handle(
            new JobKVDTO(jobId: $jobId),
            $kv,
            allowedStatuses: [
                FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,
                FfmpegJobStatus::S3_DOWNLOAD_RESTARTED->value,
            ],
            activeStatus: FfmpegJobStatus::S3_DOWNLOADING->value,
        );

        if (!$claimed) {
            echo "S3DownloadProcess: job {$jobId} not claimable, skipping\n";
            return;
        }

        $existing = $kv->get($jobId);
        $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        try {
            $s3Service = new S3ClientService();
            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'];

            $localDir = $filesDirectory . 'tmp_jobs/' . $jobId . '/';
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $localFileName = basename($jobData->s3Key);
            $localPath = $localDir . $localFileName;

            $s3Service->download($jobData->s3Bucket ?? $_ENV['S3_BUCKET'], $jobData->s3Key, $localPath);

            $fileSizeMb = round(filesize($localPath) / 1048576, 2);
            echo "S3DownloadProcess: job {$jobId} downloaded {$fileSizeMb}MB\n";

            $inputFile = 'tmp_jobs/' . $jobId . '/' . $localFileName;

            static::kvMerge($kv, new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::PENDING->value,
                s3Download: new S3DownloadJobDTO(
                    inputFile: $inputFile,
                ),
            ));

            echo "S3DownloadProcess: job {$jobId} downloaded, ready for ffmpeg\n";
        } catch (\Throwable $e) {
            echo "S3DownloadProcess: error for job {$jobId}: {$e->getMessage()}\n";
            static::handleError($kv, $jobId, $e, $jobData->maxRetries ?? 3, $jobData->retryCount ?? 0);
        }
    }

    private static function handleError(
        NatsKeyValueInterface $kv,
        string $jobId,
        \Throwable $e,
        int $maxRetries,
        int $retryCount,
    ): void {
        if ($retryCount >= $maxRetries) {
            static::kvMerge($kv, new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::S3_DOWNLOAD_FAILED->value,
                finishedAt: date('c'),
                s3Download: new S3DownloadJobDTO(
                    errors: [['message' => $e->getMessage(), 'at' => date('c')]],
                ),
            ));

            return;
        }

        static::kvMerge($kv, new JobKVDTO(
            jobId: $jobId,
            status: FfmpegJobStatus::S3_DOWNLOAD_PENDING->value,
            retryCount: $retryCount + 1,
            s3Download: new S3DownloadJobDTO(
                errors: [['message' => $e->getMessage(), 'at' => date('c')]],
            ),
        ));

        echo "S3DownloadProcess: job {$jobId} retry {$retryCount}/{$maxRetries}\n";
    }

    protected static function setEventLoop(): void
    {
        static::$eventLoop = Swoole::class;
    }
}
