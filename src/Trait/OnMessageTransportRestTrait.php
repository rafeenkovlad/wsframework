<?php

declare(strict_types=1);

namespace WsFramework\Trait;

use WsFramework\Attribute\RestRoute;
use WsFramework\Dto\MethodDTO;
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

trait OnMessageTransportRestTrait
{
    use TransportStrategyTrait;
    private static int $requestId = 0;

    /**
     * @var array<string, string>
     */
    private array $restMethods = [
        'POST' => [], 'GET' => [], 'PUT' => [], 'DELETE' => [], 'PATCH' => [], 'OPTIONS' => [],
    ];

    /**
     * @return callable
     */
    public function onMessage(): callable
    {
        return function (TcpConnection $connection, string|array|Request $data) {
            Coroutine::create(function () use ($connection, $data) {
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

                $methodDTO = static::restGetMethod($data, $methodClass::getMethodName(), $headers, $payload);

                if (static::isCondition(
                    $connection,
                    $methodDTO,
                )
                ) {
                    static::payload($methodDTO, $connection);
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
        $relativeNamespace = substr(static::namespaceAction(), strlen('App\\Workerman\\'));
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
                $methodClass = static::namespaceAction() . str_replace('/', '\\', $action);
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
}
