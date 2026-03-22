<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\FfmpegQueueProcess;

use WsFramework\Action\Method\FfmpegQueue\Dlq;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsDlqChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\FfmpegJobDTO;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\Video\FfmpegJobExecutor;
use WsFramework\Service\Video\FfmpegVideoConverter;
use Basis\Nats\Message\Msg;
use Workerman\Worker;

class FfmpegQueueDLQProcess extends BackgroundProcessAbstract
{
    protected static function constructor(): void
    {
    }

    protected static function setCount(): void
    {
        static::$count = (int)$_ENV['FFMPEG_QUEUE_DLQ_COUNT_PROCESS'];
    }

    protected static function setHost(): void
    {
        static::$host = $_ENV['FFMPEG_QUEUE_DLQ_PROCESS_HOST'];
    }

    protected static function setPort(): void
    {
        static::$port = $_ENV['FFMPEG_QUEUE_DLQ_PROCESS_PORT'];
    }

    public static function setProcessName(): void
    {
        static::$nameProcess = 'FfmpegQueueDLQProcess';
    }

    protected static function setProtocol(): void
    {
        static::$protocol = 'unix';
    }

    public static function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            FfmpegNatsDlqChannel::main();
            S3NatsChannel::main();

            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'] ?? '/var/www/html/public/files/';
            $executor = new FfmpegJobExecutor(
                converter: new FfmpegVideoConverter(filesDirectory: $filesDirectory),
                filesDirectory: $filesDirectory,
                kv: FfmpegNatsDlqChannel::eventInterface()->bucket('ffmpeg_jobs_status'),
            );

            $dlqCallback = function (Msg $msg) use ($executor) {
                echo "FfmpegQueueDLQProcess: [DLQ] {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $originalData = $data['originalData'] ?? $data;
                $executor->execute(FfmpegJobDTO::createFromArray($originalData));
            };

            FfmpegNatsDlqChannel::eventInterface()->on($dlqCallback, Dlq::getMethodName());

            echo "FfmpegQueueDLQProcess consumer started on worker {$worker->id}\n";
        };
    }
}
