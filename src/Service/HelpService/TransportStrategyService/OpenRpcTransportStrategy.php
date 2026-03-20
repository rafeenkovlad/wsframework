<?php

namespace WsFramework\Service\HelpService\TransportStrategyService;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Dto\MethodDTO;
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine;
use Workerman\Protocols\Http\Request;

class OpenRpcTransportStrategy implements TransportStrategyInterface
{
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

    /**
     * @param array $data
     * @param array|null $headers
     * @return void
     */
    private static function dataWithHeaders(array &$data, ?array $headers): void
    {
        if ($headers) {
            $data['headers'] = $headers;
        } else {
            $data['headers'] = [];
        }
    }

    private static function dataWithPayload(array &$data, ?array $payload): void
    {
        if ($payload) {
            $data['payload'] = $payload;
        }
    }

    protected function responseMethodNotFound(TcpConnection $connection, MethodDTO $methodDTO): void
    {
        echo 'warning:  method not found' . "\n";
        // TODO: implement responseMethodNotFound in application layer
    }

    protected function responseBadRequest(TcpConnection $connection): void
    {
        echo 'warning:  bad request' . "\n";
        // TODO: implement responseBadRequest in application layer
    }

    protected static function publishChannel(TcpConnection $connection, MethodDTO $methodDTO, string $methodClass): void
    {
        echo 'connection_id: ' . $connection->id . "\n";
        echo 'method: ' . $methodDTO->method . "\n";
        echo 'method_class: ' . $methodClass . "\n";

        /** @var MethodAbstract $methodClass*/
        $methodClass::publishChannel(
            $connection->worker->id,
            $connection->id,
            $methodDTO,
        );
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
