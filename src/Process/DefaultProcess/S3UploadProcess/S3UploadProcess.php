<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\S3UploadProcess;

use JsonException;
use Workerman\Coroutine;
use Workerman\Events\Swoole;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Dto\UseCase\FfmpegJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\S3UploadJobDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\S3\S3ClientService;
use WsFramework\UseCase\ClaimJobStageUseCase;
use WsFramework\UseCase\CleanupJobDirectoryUseCase;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\RecoverStuckJobsUseCase;
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

            $mainCallback = function (Msg $msg) {
                echo "S3UploadProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;

                $kv = S3NatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
                $e = null;

                try {
                    static::executeUpload($jobId, $kv);
                } catch (PipelineException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    static::kvMerge($kv, new JobKVDTO(
                        jobId: $jobId,
                        status: FfmpegJobStatus::S3_UPLOAD_RESTARTED->value,
                        s3Upload: new S3UploadJobDTO(
                            errors: [['message' => $e->getMessage(), 'at' => date('c')]],
                        ),
                    ));
                } finally {
                    if (!$e instanceof PipelineException) {
                        DispatchJobByStatusUseCase::handle($jobId, $kv);
                    }
                }
            };

            Coroutine::create(function () use ($mainCallback) {
                S3NatsChannel::eventInterface()->on($mainCallback, S3NatsChannel::METHOD_UPLOAD);
            });

//            Coroutine::create(function () {
//                static::recoveryJob();
//            });
            echo "S3UploadProcess consumer started on worker {$worker->id}\n";
        };
    }

    /**
     * @param string $jobId
     * @param NatsKeyValueInterface $kv
     * @return void
     * @throws JsonException
     * @throws UseCaseException
     */
    private static function executeUpload(string $jobId, NatsKeyValueInterface $kv): void
    {
        $claimed = ClaimJobStageUseCase::handle(
            new JobKVDTO(jobId: $jobId),
            $kv,
            allowedStatuses: [
                FfmpegJobStatus::S3_UPLOAD_PENDING->value,
                FfmpegJobStatus::S3_UPLOAD_RESTARTED->value,
            ],
            activeStatus: FfmpegJobStatus::S3_UPLOADING->value,
        );

        if (!$claimed) {
            echo "S3UploadProcess: job {$jobId} not claimable, skipping\n";
            return;
        }

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
                in_array(pathinfo($f, PATHINFO_EXTENSION), ['m3u8', 'ts'], true)
            );
            $tsCount = count(array_filter($hlsFiles, fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'ts'));
            if ($tsCount === 0) {
                throw new \RuntimeException("No .ts segments in {$localHlsDir}");
            }
            if ($expectedSegments !== null && $tsCount !== $expectedSegments) {
                throw new \RuntimeException(
                    "Segment count mismatch for job {$jobId}: expected {$expectedSegments}, found {$tsCount}"
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

            static::kvMerge($kv, new JobKVDTO(
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
                status: FfmpegJobStatus::S3_UPLOAD_FAILED->value,
                finishedAt: date('c'),
                s3Upload: new S3UploadJobDTO(
                    errors: [['message' => $e->getMessage(), 'at' => date('c')]],
                ),
            ));

            $existing = $kv->get($jobId);
            if ($existing) {
                $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
                CleanupJobDirectoryUseCase::handle(new JobKVDTO(
                    jobId: $jobId,
                    ffmpegJob: new FfmpegJobDTO(localHlsDir: $jobData->ffmpegJob?->localHlsDir),
                ));
            }

            return;
        }

        static::kvMerge($kv, new JobKVDTO(
            jobId: $jobId,
            status: FfmpegJobStatus::S3_UPLOAD_PENDING->value,
            retryCount: $retryCount + 1,
            s3Upload: new S3UploadJobDTO(
                errors: [['message' => $e->getMessage(), 'at' => date('c')]],
            ),
        ));

        echo "S3UploadProcess: job {$jobId} retry {$retryCount}/{$maxRetries}\n";
    }

    protected static function setEventLoop(): void
    {
        static::$eventLoop = Swoole::class;
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
        RecoverStuckJobsUseCase::handle($kv, [FfmpegJobStatus::S3_UPLOADING]);
    }
}
