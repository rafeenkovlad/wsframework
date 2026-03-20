<?php

namespace WsFramework\Trait;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Dto\MethodDTO;
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine;
use Workerman\Protocols\Http\Request;

trait OnMessageTransportOpenRpcTrait
{
    /**
     * @var array
     */
    private array $methods;

    /**
     * @return callable
     */
    public function onMessage(): callable
    {
        return function (TcpConnection $connection, string|array|Request $data) {
            Coroutine::create(function () use ($connection, $data) {
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

                if ($methodDTO && static::isCondition(
                        $connection,
                        $methodDTO,
                    )
                ) {
                    static::payload($methodDTO, $connection);
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

    protected static function responseMethodNotFound(TcpConnection $connection, MethodDTO $methodDTO): void
    {
        echo 'warning:  method not found' . "\n";
    }

    protected static function responseBadRequest(TcpConnection $connection): void
    {
        echo 'warning:  bad request' . "\n";
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

    /**
     * Условие для обработки реквеста
     *
     * @param TcpConnection $connection
     * @param MethodDTO $methodDTO
     * @return bool
     */
    abstract protected static function isCondition(
        TcpConnection $connection,
        MethodDTO $methodDTO,
    ): bool;

    /**
     * Дополнить входящий метод аргументами
     * @param MethodDTO $methodDTO
     * @param TcpConnection $connection
     * @return void
     */
    protected static function payload(MethodDTO $methodDTO, TcpConnection $connection): void
    {

    }

    protected static function defineMethodClass(string $method): string
    {
        return static::namespaceAction() . '\\' . ucfirst(str_replace('.', '\\', $method));
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

    private static function isMethodExists(string $method): bool
    {
        return class_exists(static::defineMethodClass($method));
    }
}
