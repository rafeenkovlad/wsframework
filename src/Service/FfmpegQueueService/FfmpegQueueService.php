<?php

declare(strict_types=1);

namespace WsFramework\Service\FfmpegQueueService;

use WsFramework\Action\Method\FfmpegQueue\AddJob;
use WsFramework\Dto\MethodDTO;
use WsFramework\Service\ServiceAbstract;
use WsFramework\Service\HelpService\TransportStrategyService\TransportStrategyInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class FfmpegQueueService extends ServiceAbstract
{
    public function __construct(
        protected ?TransportStrategyInterface $transport = null,
    ) {
        parent::__construct($transport);
    }

    public function onWorkerStart(): callable
    {
        return function (Worker $worker) {
            // Подписываем методы на канал
            AddJob::onChannel($worker);

            // Таймер обработки очереди (каждые 1с)
            Timer::add(1, function () {
                static::processQueue();
            });

            echo "FfmpegQueueService started on worker {$worker->id}\n";
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

    private static function processQueue(): void
    {
        // TODO: реализовать обработку очереди
    }
}
