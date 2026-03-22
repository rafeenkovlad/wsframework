<?php

declare(strict_types=1);

namespace WsFramework\Service\HelpService\TransportStrategyService;

use WsFramework\Action\Response\MethodNotFound;
use WsFramework\Action\Response\ResponseAbstract;
use WsFramework\Dto\MethodDTO;
use WsFramework\Trait\TransportStrategyTrait;
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine;
use Workerman\Protocols\Http\Request;

class OpenRpcTransportStrategy implements TransportStrategyInterface
{
    use TransportStrategyTrait;
    private const GC_EVERY_MESSAGES = 10;

    public function __construct(
        private string $namespaceMethods,
        private int $processedMessages = 0,
    )
    {
    }

    /**
     * @var array
     */
    private array $methods;

    final public function onMessage(
        ?callable $isCondition
    ): callable
    {
        return function (TcpConnection $connection, string|array|Request $data) use ($isCondition) {
            if ($data instanceof Request && $data->method() === 'OPTIONS') {
                $connection->headers = ResponseAbstract::defaultHeaders();
                $connection->send('');
                return;
            }

            Coroutine::create(function () use ($connection, $data, $isCondition) {
                echo "onMessage\n";

                $headers = static::getHeaders($data);
                $payload = static::getPayload($connection);
                $methodDTO = static::getMethod($data, $headers, $payload);

                if (!$methodDTO || !$methodDTO->method) {
                    static::responseBadRequest($connection);
                    return;
                }

                if (!$this->getMethodClassFromArray($methodDTO->method)) {
                    if (!static::isMethodExists($methodDTO->method)) {
                        static::responseMethodNotFound($connection, $methodDTO);
                        return;
                    } else {
                        $this->registerMethodClass($methodDTO->method);
                    }
                }

                if ($methodDTO && $isCondition && $isCondition($connection, $methodDTO)) {
                    static::publishChannel($connection, $methodDTO, $this->getMethodClassFromArray($methodDTO->method));
                    static::trasher();
                }
            });
        };
    }

    /**
     * @param string|array|Request $data
     * @return array
     */
    private static function getHeaders(string|array|Request $data): array
    {
        if ($data instanceof Request) {
            return $data->header();
        }

        return [];
    }

    private static function getPayload(TcpConnection $connection): array
    {
        return [
            'connectionId' => $connection->id,
            'workerId' => $connection->worker->id,
        ];
    }

    private static function dataToRawBody(string|array|Request &$data): void
    {
        if ($data instanceof Request) {
            $data = $data->rawBody();
        }
    }

    /**
     * @param string|array|Request $data
     * @param array $headers
     * @param array $payload
     * @return MethodDTO|null
     */
    public static function getMethod(string|array|Request &$data, array $headers = [], array $payload = []): ?MethodDTO
    {
        static::dataToRawBody($data);
        static::dataToArray($data);

        if (is_null($data)) {
            return null;
        }

        static::dataWithHeaders($data, $headers);
        static::dataWithPayload($data, $payload);
        return MethodDTO::createFromArray(
            [
                'id' => $data['id'],
                'method' => $data['method'],
                'params' => $data['params'],
                'headers' => $data['headers'],
                'payload' => $data['payload'],
                'response' => [
                    'id' => $data['id'],
                    'fromMethod' => $data['method'],
                ],
            ],
        );
    }

    /**
     * @param string|array $data
     * @return void
     */
    private static function dataToArray(string|array &$data): void
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
    }

    protected function responseMethodNotFound(TcpConnection $connection, MethodDTO $methodDTO): void
    {
        echo 'warning:  method not found' . "\n";
        MethodNotFound::apply($connection, $methodDTO->response);
        $connection->close();
    }

    protected function responseBadRequest(TcpConnection $connection): void
    {
        echo 'warning:  bad request' . "\n";
        $connection->headers = ResponseAbstract::defaultHeaders();
        $connection->send(json_encode(['error' => 'Bad Request']));
        $connection->close();
    }

    protected function defineMethodClass(string $method): string
    {
        return $this->namespaceMethods . '\\' . ucfirst(str_replace('.', '\\', $method));
    }

    /**
     * @param string $method
     * @return string|null
     */
    private function getMethodClassFromArray(string $method): ?string
    {
        return $this->methods[$method] ?? null;
    }

    /**
     * @param string $method
     * @return string|null
     */
    private function registerMethodClass(string $method): ?string
    {
        return $this->methods[$method] = static::defineMethodClass($method);
    }

    private function isMethodExists(string $method): bool
    {
        return class_exists(static::defineMethodClass($method));
    }

    /**
     * сборщик мусора
     * @return void
     */
    protected function trasher(): void
    {
        echo 'memory before: ', memory_get_usage(), PHP_EOL;

        $this->processedMessages++;

        if ($this->processedMessages < self::GC_EVERY_MESSAGES) {
            return;
        }

        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        $this->processedMessages = 0;
        echo 'memory cleared: ', memory_get_usage(), PHP_EOL;
    }

    public function boot(): void
    {
    }
}
