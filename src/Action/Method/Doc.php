<?php

declare(strict_types=1);

declare(strict_types=1);

namespace WsFramework\Action\Method;

use PSX\OpenAPI\Info;
use PSX\OpenAPI\Server;
use PSX\OpenRPC\ContentDescriptor;
use PSX\OpenRPC\Method;
use PSX\OpenRPC\OpenRPC;
use WsFramework\Action\Method\FfmpegQueue\CancelJob;
use WsFramework\Action\Method\FfmpegQueue\DeleteJob;
use WsFramework\Action\Method\FfmpegQueue\Dlq;
use WsFramework\Action\Method\FfmpegQueue\GetJobStatus;
use WsFramework\Action\Method\FfmpegQueue\GetQueueStats;
use WsFramework\Action\Method\FfmpegQueue\ListJobs;
use WsFramework\Action\Method\FfmpegQueue\ProcessVideoCallback;
use WsFramework\Action\Method\FfmpegQueue\PurgeCompleted;
use WsFramework\Action\Method\FfmpegQueue\RestartJob;
use WsFramework\Action\Response\Doc as DocResponse;
use WsFramework\Dto\MethodDTO;
use WsFramework\Pool\Http\PoolHttpConnection;

class Doc extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'Doc';
    }

    public static function getResponseClass(): string
    {
        return DocResponse::class;
    }

    public static function getChannelClass(): ?string
    {
        return null;
    }

    public static function getPoolConnectionClass(): ?string
    {
        return PoolHttpConnection::class;
    }

    public static function isDisabledResponse(): bool
    {
        return false;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
        $errors = null;
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        $info = new Info();
        $info->setTitle('WsFramework FFmpeg Queue OpenRPC API');
        $info->setVersion('1.0.0');
        $info->setDescription(
            "OpenRPC-описание текущего FFmpeg queue сервиса.\n" .
            "Все методы вызываются через HTTP POST и JSON body.\n" .
            "Параметры верхнего уровня: id, method, params.",
        );

        $server = new Server();
        $server->setUrl(static::defineServerUrl($methodDTO));

        $methods = static::buildMethods();
        $methodName = is_array($methodDTO->params)
            ? ($methodDTO->params['getMethodByName'] ?? null)
            : null;

        if (is_string($methodName) && $methodName !== '') {
            $methods = array_values(array_filter(
                $methods,
                static fn(Method $method): bool => $method->getName() === $methodName,
            ));
        }

        $doc = new OpenRPC();
        $doc->setInfo($info);
        $doc->setServers([$server]);
        $doc->setMethods($methods);

        return ['doc' => $doc];
    }

    protected static function getDescription(): string
    {
        return 'Получить OpenRPC-описание всех методов сервиса или одного метода по имени';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        $params = static::getContentDescriptor();
        $params->setName('getMethodByName');
        $params->setSchema([
            'type' => 'string',
            'description' => 'Опционально. Если передан, возвращается описание только указанного метода.',
        ]);

        return [$params];
    }

    protected static function getResult(): ?array
    {
        return [];
    }

    protected static function getSchemaResponse(): ContentDescriptor
    {
        $result = static::getContentDescriptor();
        $result->setName('response');
        $result->setSchema([
            'type' => 'object',
            'required' => ['openrpc', 'info', 'methods'],
            'properties' => [
                'openrpc' => [
                    'type' => 'string',
                ],
                'info' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                'servers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
                'methods' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['name', 'params', 'result'],
                        'additionalProperties' => true,
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                            ],
                            'summary' => [
                                'type' => 'string',
                            ],
                            'description' => [
                                'type' => 'string',
                            ],
                            'params' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                ],
                            ],
                            'result' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'additionalProperties' => true,
        ]);

        return $result;
    }

    /**
     * @return array<int, Method>
     */
    private static function buildMethods(): array
    {
        return array_map(
            static fn(string $methodClass): Method => $methodClass::toOpenRpcMethod(),
            static::documentedMethodClasses(),
        );
    }

    /**
     * @return array<int, class-string<MethodOpenRPCAbstract>>
     */
    private static function documentedMethodClasses(): array
    {
        return [
            self::class,
            ProcessVideoCallback::class,
            GetJobStatus::class,
            ListJobs::class,
            GetQueueStats::class,
            CancelJob::class,
            RestartJob::class,
            DeleteJob::class,
            PurgeCompleted::class,
            Dlq::class,
        ];
    }

    private static function defineServerUrl(MethodDTO $methodDTO): string
    {
        $scheme = $methodDTO->getHeader('x-forwarded-proto')
            ?? $methodDTO->getHeader('X-Forwarded-Proto')
            ?? 'http';

        $host = $methodDTO->getHeader('host') ?? $methodDTO->getHeader('Host');
        if (is_string($host) && $host !== '') {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . ($_ENV['NATS_JETSTREAM_HTTP_HOST'] ?? '127.0.0.1')
            . ':' . ($_ENV['NATS_JETSTREAM_HTTP_PORT'] ?? '8091');
    }
}
