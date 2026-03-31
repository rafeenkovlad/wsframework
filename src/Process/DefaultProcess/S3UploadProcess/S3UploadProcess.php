<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\S3UploadProcess;

use JsonException;
use Workerman\Coroutine;
use Workerman\Coroutine\Channel;
use Workerman\Coroutine\WaitGroup;
use Workerman\Events\Swoole;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\DefaultDTO;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Dto\UseCase\FfmpegJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\S3UploadJobDTO;
use WsFramework\Enum\ClaimResult;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\NatsSubject;
use WsFramework\Enum\NatsSubjectEnum;
use WsFramework\Enum\Pipeline;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\S3\S3ClientService;
use WsFramework\UseCase\ClaimJobStageUseCase;
use WsFramework\UseCase\CleanupJobDirectoryUseCase;
use WsFramework\UseCase\DefineCurrentChannelUseCase;
use WsFramework\UseCase\DefineCurrentPipelineUseCase;
use WsFramework\UseCase\GetKVInterfaceUseCase;
use WsFramework\UseCase\RecoverStuckJobsUseCase;
use Basis\Nats\Message\Msg;
use Workerman\Worker;
use WsFramework\UseCase\ThrowableHandleUseCase;

class S3UploadProcess extends BackgroundProcessAbstract
{
    private static S3ClientService $s3Service;

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

    /**
     * @return callable
     */
    public static function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            $config = DefaultDTO::createWithDefaultValues();
            $config->pipeline = Pipeline::S3_UPLOAD;
            $config->channel = NatsChannel::main();
            DefineCurrentPipelineUseCase::handle($config);
            DefineCurrentChannelUseCase::handle($config);
            KVNatsBucket::main();

            static::$s3Service = new S3ClientService();

            static::recoveryJob();

            $mainCallback = function (Msg $msg) {
                echo "S3UploadProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;

                static::checkFailed($jobId);
                static::checkClaimedStart($jobId);
                static::executeUpload($jobId);
            };

            Coroutine::create(function () use ($mainCallback) {
                $subject = NatsSubjectEnum::S3_UPLOAD->getValue();
                NatsChannel::factoryListener($subject)
                    ->on($mainCallback, $subject);
            });

            echo "S3UploadProcess consumer started on worker {$worker->id}\n";
        };
    }

    /**
     * @param string $jobId
     * @return void
     * @throws JsonException
     * @throws PipelineException
     */
    private static function checkFailed(string $jobId): void
    {
        $kv = GetKVInterfaceUseCase::handle();
        $existing = $kv->get($jobId);
        $jobKVDTO = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        if (FfmpegJobStatus::S3_UPLOAD_FAILED->isEq($jobKVDTO->status)) {
            $ex = new PipelineException('S3UploadProcess', "job {$jobId} skip — S3_UPLOAD_FAILED\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    /**
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
                FfmpegJobStatus::S3_UPLOAD_PENDING->value,
                FfmpegJobStatus::S3_UPLOAD_RESTARTED->value,
            ],
            activeStatus: FfmpegJobStatus::S3_UPLOADING->value,
        );

        if ($result !== ClaimResult::CLAIMED) {
            $ex = new PipelineException('S3UploadProcess', "job {$jobId} skip — {$result->name}\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    /**
     * @param string $jobId
     * @return void
     * @throws JsonException
     * @throws UseCaseException
     */
    private static function executeUpload(string $jobId): void
    {
        $kv = GetKVInterfaceUseCase::handle();
        $existing = $kv->get($jobId);
        $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        try {
            $localHlsDir = $jobData->ffmpegJob?->localHlsDir;
            $playlistFile = $jobData->ffmpegJob?->playlistFile;
            $expectedSegments = $jobData->ffmpegJob?->segmentCount;
            $bucket = $jobData->s3Bucket ?? $_ENV['S3_BUCKET'];
            $s3Prefix = $jobData->outputS3Prefix ?? '';

            if (!$localHlsDir || !$playlistFile) {
                throw new \RuntimeException("localHlsDir and playlistFile required for job {$jobId}");
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
                in_array(pathinfo($f, PATHINFO_EXTENSION), ['m3u8', 'ts'], true),
            );
            $tsCount = count(array_filter($hlsFiles, fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'ts'));
            if ($tsCount === 0) {
                throw new \RuntimeException("No .ts segments in {$localHlsDir}");
            }
            if ($expectedSegments !== null && $tsCount !== $expectedSegments) {
                throw new \RuntimeException(
                    "Segment count mismatch for job {$jobId}: expected {$expectedSegments}, found {$tsCount}",
                );
            }

            $concurrency = (int) ($_ENV['S3_UPLOAD_CONCURRENCY']);
            $channel = new Channel($concurrency);
            $wg = new WaitGroup();
            $errCh = new Channel(1);

            foreach ($hlsFiles as $file) {
                $channel->push(true);
                $wg->add();
                Coroutine::create(function () use ($file, $bucket, $s3Prefix, $channel, $wg, $errCh) {
                    try {
                        if ($errCh->length() > 0) {
                            return;
                        }
                        $ext = pathinfo($file, PATHINFO_EXTENSION);
                        $contentType = match ($ext) {
                            'm3u8' => 'application/vnd.apple.mpegurl',
                            'ts' => 'video/MP2T',
                            default => 'application/octet-stream',
                        };
                        static::$s3Service->uploadFile($file, $bucket, $s3Prefix . basename($file), $contentType);
                    } catch (\Throwable $e) {
                        if ($errCh->length() === 0) {
                            $errCh->push($e);
                        }
                    } finally {
                        $channel->pop();
                        $wg->done();
                    }
                });
            }
            $wg->wait();

            if ($errCh->length() > 0) {
                throw $errCh->pop();
            }

            static::kvMerge(new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::COMPLETED->value,
                finishedAt: date('c'),
                s3Upload: new S3UploadJobDTO(
                    playlistS3Key: $s3Prefix . $playlistFile,
                ),
            ));

            echo "S3UploadProcess: job {$jobId} completed\n";

            CleanupJobDirectoryUseCase::handle(new JobKVDTO(
                jobId: $jobId,
                ffmpegJob: new FfmpegJobDTO(localHlsDir: $localHlsDir),
            ));
        } catch (\Throwable $e) {
            echo "S3UploadProcess: error for job {$jobId}: {$e->getMessage()}\n";
            echo "S3UploadProcess: job {$jobId} retry {$jobData->retryCount}/{$jobData->maxRetries}\n";

            ThrowableHandleUseCase::handle($jobData, $e);
        }
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
            [FfmpegJobStatus::S3_UPLOADING, FfmpegJobStatus::S3_UPLOAD_PENDING, FfmpegJobStatus::S3_UPLOAD_RESTARTED],
        );
    }

    protected static function setEventLoop(): void
    {
        static::$eventLoop = Swoole::class;
    }
}
