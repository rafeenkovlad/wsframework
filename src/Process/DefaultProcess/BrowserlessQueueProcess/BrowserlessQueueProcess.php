<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess\BrowserlessQueueProcess;

use JsonException;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Dto\DefaultDTO;
use WsFramework\Dto\StagePayloadDTO;
use WsFramework\Enum\BrowserlessJobStatus;
use WsFramework\Enum\NatsSubjectEnum;
use WsFramework\Enum\Pipeline;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\Service\Browserless\BrowserlessJobExecutor;
use WsFramework\Service\Browserless\ProfilePoolManager;
use WsFramework\UseCase\DefineCurrentChannelUseCase;
use WsFramework\UseCase\DefineCurrentPipelineUseCase;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\RecoverStuckJobsUseCase;
use Basis\Nats\Message\Msg;
use Workerman\Worker;

class BrowserlessQueueProcess extends BackgroundProcessAbstract
{
    protected static function constructor(): void
    {
    }

    protected static function setCount(): void
    {
        static::$count = (int)$_ENV['BROWSERLESS_QUEUE_COUNT_PROCESS'];
    }

    protected static function setHost(): void
    {
        static::$host = $_ENV['BROWSERLESS_QUEUE_PROCESS_HOST'];
    }

    protected static function setPort(): void
    {
    }

    public static function setProcessName(): void
    {
        static::$nameProcess = 'BrowserlessQueueProcess';
    }

    protected static function setProtocol(): void
    {
        static::$protocol = 'unix';
    }

    public static function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            $config = DefaultDTO::createWithDefaultValues();
            $config->pipeline = Pipeline::BROWSERLESS;
            $config->channel = NatsChannel::main();
            DefineCurrentPipelineUseCase::handle($config);
            DefineCurrentChannelUseCase::handle($config);
            KVNatsBucket::main();

            // Инициализировать пул профилей
            $profilePool = new ProfilePoolManager($_ENV['BROWSERLESS_PROFILES_DIR'] ?? '/var/www/html/storage/profiles');
            $profilePool->initializePool();

            static::recoveryJob();

            $apiUrl = $_ENV['BROWSERLESS_API_URL'];
            $executor = new BrowserlessJobExecutor(apiUrl: $apiUrl);

            $mainCallback = function (Msg $msg) use ($executor) {
                echo "BrowserlessQueueProcess: received message: {$msg->payload->body}\n";
                $data = json_decode($msg->payload->body, true, 512, JSON_THROW_ON_ERROR);
                $dto = StagePayloadDTO::createFromArray($data);
                $jobId = $dto->jobId;
                $job = $executor->execute($jobId);
                DispatchJobByStatusUseCase::handle($job);
            };

            $subject = NatsSubjectEnum::BROWSERLESS_JOB->getValue();
            try {
                NatsChannel::factoryListener($subject)
                    ->on($mainCallback, $subject);
            } finally {
                exec('php ' . HOME . '/public/browserless-worker.php reload');
                echo "Channel with subject: " . $subject . " not alive. Process reloading...\n";
            }

            echo "BrowserlessQueueProcess consumer started on worker {$worker->id}\n";
        };
    }

    /**
     * @return void
     * @throws JsonException
     * @throws PipelineException
     * @throws UseCaseException
     * @throws \Throwable
     */
    private static function recoveryJob(): void
    {
        RecoverStuckJobsUseCase::handle(
            [BrowserlessJobStatus::BROWSERLESS_PROCESSING, BrowserlessJobStatus::BROWSERLESS_PROCESSING_RESTARTED, BrowserlessJobStatus::BROWSERLESS_PENDING],
        );
    }
}
