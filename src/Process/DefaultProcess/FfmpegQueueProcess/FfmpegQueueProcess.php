<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\FfmpegQueueProcess;

use JsonException;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Dto\UseCase\FfmpegJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\Video\FfmpegJobExecutor;
use WsFramework\Service\Video\FfmpegVideoConverter;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\RecoverStuckJobsUseCase;
use Basis\Nats\Message\Msg;
use Workerman\Worker;

class FfmpegQueueProcess extends BackgroundProcessAbstract
{
    protected static function constructor(): void
    {
    }

    protected static function setCount(): void
    {
        static::$count = (int)$_ENV['FFMPEG_QUEUE_COUNT_PROCESS'];
    }

    protected static function setHost(): void
    {
        static::$host = $_ENV['FFMPEG_QUEUE_PROCESS_HOST'];
    }

    protected static function setPort(): void
    {
        static::$port = $_ENV['FFMPEG_QUEUE_PROCESS_PORT'];
    }

    public static function setProcessName(): void
    {
        static::$nameProcess = 'FfmpegQueueProcess';
    }

    protected static function setProtocol(): void
    {
        static::$protocol = 'unix';
    }

    public static function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            FfmpegNatsChannel::main();
            S3NatsChannel::main();

            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'] ?? '/var/www/html/public/files/';
            $executor = new FfmpegJobExecutor(
                converter: new FfmpegVideoConverter(filesDirectory: $filesDirectory),
                filesDirectory: $filesDirectory,
                kv: FfmpegNatsChannel::eventInterface()->bucket('ffmpeg_jobs_status'),
            );

            $mainCallback = function (Msg $msg) use ($executor) {
                echo "FfmpegQueueProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;

                $kv = FfmpegNatsChannel::eventInterface()->bucket('ffmpeg_jobs_status');
                $e = null;
                try {
                    $executor->execute($jobId);
                } catch (PipelineException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    static::kvMerge($kv, new JobKVDTO(
                        jobId: $jobId,
                        status: FfmpegJobStatus::PROCESSING_RESTARTED->value,
                        ffmpegJob: new FfmpegJobDTO(
                            errors: [['message' => $e->getMessage(), 'at' => date('c')]],
                        ),
                    ));
                } finally {
                    if (!$e instanceof PipelineException) {
                        DispatchJobByStatusUseCase::handle($jobId, $kv);
                    }
                }
            };

            FfmpegNatsChannel::eventInterface()->on($mainCallback, FfmpegNatsChannel::METHOD_JOB);

            static::recoveryJob();

            echo "FfmpegQueueProcess consumer started on worker {$worker->id}\n";
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
        RecoverStuckJobsUseCase::handle($kv, [FfmpegJobStatus::PROCESSING]);
    }
}
