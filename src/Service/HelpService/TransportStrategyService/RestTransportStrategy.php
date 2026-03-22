<?php

declare(strict_types=1);

namespace WsFramework\Service\HelpService\TransportStrategyService;

use WsFramework\Attribute\RestRoute;
use WsFramework\Dto\MethodDTO;
use WsFramework\Trait\TransportStrategyTrait;
use FilesystemIterator;
use Hyperf\Stringable\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use SplFileInfo;
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine;
use Workerman\Protocols\Http\Request;

class RestTransportStrategy implements TransportStrategyInterface
{
    use TransportStrategyTrait;
    private static int $requestId = 0;

    /**
     * @var array<string, string>
     */
    private array $restMethods = [
        'POST' => [], 'GET' => [], 'PUT' => [], 'DELETE' => [], 'PATCH' => [], 'OPTIONS' => [],
    ];

    public function __construct(
        private string $namespaceMethods,
)
    {
    }

    /**
     * @param callable|null $isCondition
     * @return callable
     */
    public function onMessage(
        ?callable $isCondition,
    ): callable
    {
        return function (TcpConnection $connection, string|array|Request $data) use ($isCondition) {
            Coroutine::create(function () use ($connection, $data, $isCondition) {
                echo "onMessage\n";

                if (!$data instanceof Request) {
                    static::responseBadRequest($connection);
                    return;
                }

                $headers = static::restGetHeaders($data);
                $payload = static::restGetPayload($connection, $data);
                $methodClass = $this->restGetMethodClassFromArray($payload['httpMethod'], $payload['path']);
                if (!$methodClass) {
                    static::responseMethodNotFound($connection, MethodDTO::createWithDefaultValues());
                    return;
                }

                /** @var MethodAbstract $methodClass */
                $methodDTO = static::restGetMethod($data, $methodClass::getMethodName(), $headers, $payload);

                if ($methodDTO && $isCondition && $isCondition($connection, $methodDTO)) {
                    static::publishChannel($connection, $methodDTO, $methodClass);
                    static::trasher();
                }
            });
        };
    }

    /**
     * @param Request $request
     * @return array
     */
    private static function restGetHeaders(Request $request): array
    {
        return $request->header();
    }

    private static function restGetPayload(TcpConnection $connection, Request $request): array
    {
        return [
            'connectionId' => $connection->id,
            'workerId' => $connection->worker->id,
            'httpMethod' => $request->method(),
            'path' => $request->path(),
        ];
    }

    /**
     * @param string|array|Request $data
     * @param string $methodName
     * @param array $headers
     * @param array $payload
     * @return MethodDTO|null
     */
    public static function restGetMethod(string|array|Request &$data,  string $methodName, array $headers = [], array $payload = []): ?MethodDTO
    {
        static::dataToArray($data);

        if (is_null($data)) {
            return null;
        }

        static::dataToParams($data);
        static::dataWithRequestId($data, $headers);
        static::dataWithHeaders($data, $headers);
        static::dataWithPayload($data, $payload);

        return MethodDTO::createFromArray(
            [
                'id' => $data['id'],
                'method' => $methodName,
                'params' => $data['params'],
                'headers' => $data['headers'],
                'payload' => $data['payload'],
                'response' => [
                    'id' => $data['id'],
                    'fromMethod' => $methodName,
                ],
            ],
        );
    }

    private function registerRestMethods(): void
    {
        $relativeNamespace = substr($this->namespaceMethods, strlen('App\\Workerman\\'));
        $pathByNamespace = str_replace('\\', '/', $relativeNamespace);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(HOME . '/src' . $pathByNamespace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $pattern = str_replace('/', '\\/', '#^' . HOME . '/src' . $pathByNamespace . '(.*)?\.php$#sx');

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isDir() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();
                $action = preg_replace($pattern, '$1', $filePath);
                /** @var MethodAbstract $methodClass */
                $methodClass = $this->namespaceMethods . str_replace('/', '\\', $action);
                $ref = new ReflectionClass($methodClass);
                /** @var ReflectionAttribute $attrs */
                [$attrs] = $ref->getAttributes(RestRoute::class);
                /** @var RestRoute $restRoute */
                $restRoute = $attrs->newInstance();
                $this->restRegisterMethodClass($restRoute->method, $restRoute->path, $methodClass);
            }
        }
    }

    protected static function restRequestId(): int
    {
        return static::$requestId++;
    }

    /**
     * @param string $restMethod
     * @param string $restPath
     * @return string|null
     */
    private function restGetMethodClassFromArray(string $restMethod, string $restPath): ?string
    {
        return $this->restMethods[$restMethod][$restPath] ?? null;
    }

    /**
     * @param string $method
     * @param string $route
     * @param string $methodClass
     * @return string|null
     */
    private function restRegisterMethodClass(string $method, string $route, string $methodClass): ?string
    {
        return $this->restMethods[Str::upper($method)][$route] = $methodClass;
    }

    private static function dataToArray(string|array|Request &$data): void
    {
        if ($data instanceof Request) {
            $body = $data->rawBody();
            $contentType = strtolower((string)$data->header('content-type'));

            if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($body, $parsed);
                $data = $parsed;
            } elseif (str_contains($contentType, 'application/json')) {
                $decoded = json_decode($body, true);
                $data = is_array($decoded) ? $decoded : [];
            }
        }
    }

    private static function dataWithRequestId(array &$data,  ?array $headers): void
    {
        if ($headers) {
            $data['id'] = static::restRequestId();
        }
    }


    /**
     * @param array $data
     * @return void
     */
    private static function dataToParams(array &$data): void
    {
        if(!array_key_exists('params', $data)) {
            $data = ['params' => $data];
        }
    }

    /**
     * сборщик мусора
     * @return void
     */
    protected function trasher(): void
    {
        echo 'memory before: ', memory_get_usage(), PHP_EOL;
        //gc_collect_cycles();
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

        $connection->close();
    }

    public function boot(): void
    {
        $this->registerRestMethods();
    }
}
