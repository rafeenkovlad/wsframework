<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\FfmpegQueueProcess;

use JsonException;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Dto\DefaultDTO;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Enum\NatsSubjectEnum;
use WsFramework\Enum\Pipeline;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\Video\FfmpegJobExecutor;
use WsFramework\Service\Video\FfmpegVideoConverter;
use WsFramework\UseCase\DefineCurrentChannelUseCase;
use WsFramework\UseCase\DefineCurrentPipelineUseCase;
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
            $config = DefaultDTO::createWithDefaultValues();
            $config->pipeline = Pipeline::FFMPEG;
            $config->channel = NatsChannel::main();
            DefineCurrentPipelineUseCase::handle($config);
            DefineCurrentChannelUseCase::handle($config);
            KVNatsBucket::main();

            static::recoveryJob();

            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'] ?? '/var/www/html/public/files/';
            $executor = new FfmpegJobExecutor(
                converter: new FfmpegVideoConverter(filesDirectory: $filesDirectory),
                filesDirectory: $filesDirectory,
            );

            $mainCallback = function (Msg $msg) use ($executor) {
                echo "FfmpegQueueProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;
                $job = $executor->execute($jobId);
                DispatchJobByStatusUseCase::handle($job);
            };

            $subject = NatsSubjectEnum::FFMPEG_JOB->getValue();
            NatsChannel::factoryListener($subject)
                ->on($mainCallback, $subject);

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
        RecoverStuckJobsUseCase::handle(
            [FfmpegJobStatus::PROCESSING, FfmpegJobStatus::PROCESSING_RESTARTED, FfmpegJobStatus::PENDING],
        );
    }
}
