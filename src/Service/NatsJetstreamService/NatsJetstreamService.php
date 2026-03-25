<?php

declare(strict_types=1);

namespace WsFramework\Service\NatsJetstreamService;

use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Service\ServiceAbstract;
use WsFramework\Service\HelpService\TransportStrategyService\TransportStrategyInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

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
            FfmpegNatsChannel::main();
            S3NatsChannel::main();
            KVNatsBucket::main();

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
