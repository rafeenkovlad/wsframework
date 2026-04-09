<?php

declare(strict_types=1);

namespace WsFramework\Service\NatsJetstreamService;

use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\NatsChannel\NatsChannel;
use WsFramework\Dto\DefaultDTO;
use WsFramework\Dto\MethodDTO;
use WsFramework\Service\ServiceAbstract;
use WsFramework\Service\HelpService\TransportStrategyService\TransportStrategyInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;
use WsFramework\UseCase\CheckAllQueuesIdleUseCase;
use WsFramework\UseCase\DefineCurrentChannelUseCase;

class NatsJetstreamService extends ServiceAbstract
{
    public function __construct(
        protected ?TransportStrategyInterface $transport = null,
    ) {
        parent::__construct($transport);
    }

    public function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            // Initialize NATS connection in this worker process
            $config = DefaultDTO::createWithDefaultValues();
            $config->channel = NatsChannel::main();
            DefineCurrentChannelUseCase::handle($config);
            KVNatsBucket::main();

            $interval = (int) ($_ENV['IDLE_CHECK_INTERVAL_SEC']);

            Timer::add($interval, static function (): void {
                $result = CheckAllQueuesIdleUseCase::handle();

                echo sprintf(
                    "[IdleCheck] idle=%s activeJobs=%d breakdown=%s\n",
                    $result['idle'] ? 'true' : 'false',
                    $result['activeJobs'],
                    json_encode($result['breakdown']),
                );
            });

            echo "NatsJetstreamService started on worker {$worker->id}\n";
        };
    }

    protected static function namespaceAction(): string
    {
        return '\WsFramework\Action\Method\FfmpegQueue';
    }

    protected static function isCondition(TcpConnection $connection, MethodDTO $methodDTO): bool
    {
        return true;
    }
}
