<?php

declare(strict_types=1);

namespace WsFramework\Action\Method;

use WsFramework\Action\Response\Error;
use WsFramework\Action\Response\ResponseAbstract;
use WsFramework\Channel\ChannelAbstract;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\ResponseDTO;
use WsFramework\Middleware\ApiKeyAuth;
use WsFramework\Pool\PoolConnectionInterface;
use WsFramework\Service\HelpService\CloseConnectionStrategy\CloseConnectionStrategyInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * Определяет методы для работы через каналы в других процессах | либо в текущих процессах без каналов
 */
abstract class MethodAbstract extends MethodOpenRPCAbstract
{
    /**
     * @return string
     */
    abstract public static function getMethodName(): string;

    /**
     * @return string
     */
    abstract public static function getResponseClass(): string;

    /**
     * @return ?ChannelAbstract
     */
    abstract public static function getChannelClass(): ?string;

    abstract public static function getPoolConnectionClass(): ?string;

    abstract public static function isDisabledResponse(): bool;

    abstract public static function validate(MethodDTO $methodDTO, &$errors): void;

    /**
     * @param int $workerId идентификатор воркера из которого пришел запрос
     * @param int $connectionId идентификатор подключения в этом воркере
     * @param MethodDTO $methodDTO данные запроса
     * @return array
     */
    abstract protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array;

    private static function handlerResultProcess(int $workerId, int $connectionId, MethodDTO $methodDTO): void
    {
        $methodDTO->response->result = static::process($workerId, $connectionId, $methodDTO);
    }

    /**
     * @param int $connectionId
     * @param ResponseDTO $responseDTO
     * @param string|null $responseClass
     * @return void
     */
    protected static function sendResponse(int $connectionId, ResponseDTO $responseDTO, ?string $responseClass = null): void
    {
        /**
         * @var static $methodClass
         */
        $connection = static::getConnection($connectionId);
        if ($connection) {
            /** @var ResponseAbstract $class */
            $class = $responseClass ?? static::getResponseClass();
            $class::apply($connection, $responseDTO);
        }
    }

    /**
     * Отправляем в обработку
     *
     * @param int $workerId
     * @param int $connectionId
     * @param MethodDTO $methodDTO
     * @return void
     */
    public static function publishChannel(
        int $workerId,
        int $connectionId,
        MethodDTO $methodDTO,
    ): void
    {
        if (!static::middleware($workerId, $connectionId, $methodDTO)) {
            $methodDTO->response->errors = ['message' => 'Unauthorized'];
            static::sendResponse($connectionId, $methodDTO->response, Error::class);
            return;
        }
        static::validate($methodDTO, $errors);
        if (static::isNotValidated($errors)) {
            static::errorsResponse($connectionId, $methodDTO, $errors);
            return;
        }

        if (static::isWithoutChannel()) {
            static::withoutChannelResponse($workerId, $connectionId, $methodDTO);
            return;
        }

        /**
         * @var ChannelAbstract $channelClass
         */
        $channelClass = static::getChannelClass();

        $channelClass::eventInterface()->publish(
            [$workerId, $connectionId, $methodDTO],
            $workerId . static::getMethodName()
        );
        $channelClass::eventInterface()
            ->on(function (array $data) {
                /**
                 * @var int $workerId
                 * @var int $connectionId
                 * @var MethodDTO $methodDTO
                 */
                [$workerId, $connectionId, $methodDTO] = $data;
                static::sendResponse($connectionId, $methodDTO->response);
                static::afterSendResponse($workerId, $connectionId, $methodDTO);
                static::closeConnection($workerId, $connectionId);
            }, $workerId . static::getMethodName() . 'Resp');
    }

    /**
     * @param ?Worker $worker
     * @return void
     */
    public static function onChannel(?Worker $worker = null): void
    {
        if (is_null(static::getChannelClass())) {
            return;
        }

        /**
         * @var ChannelAbstract $channelClass
         */
        $channelClass = static::getChannelClass();

        $channelClass::eventInterface()->on(function (array $data) {
            /**
             * @var int $workerId
             * @var int $connectionId
             * @var MethodDTO $methodDTO
             */
            [$workerId, $connectionId, $methodDTO] = $data;
            static::handlerResultProcess($workerId, $connectionId, $methodDTO);
            if (!static::isDisabledResponse()) {
                static::publishChannelResponse($workerId, $connectionId, $methodDTO);
            }
        }, $worker->id . static::getMethodName());
    }

    /**
     * @param int $workerId
     * @param int $connectionId
     * @param MethodDTO $methodDTO
     * @return void
     */
    private static function publishChannelResponse(int $workerId, int $connectionId, MethodDTO $methodDTO): void
    {
        /**
         * @var ChannelAbstract $channelClass
         * @var string $event
         */
        $channelClass = static::getChannelClass();
        $channelClass::eventInterface()
            ->publish([$workerId, $connectionId, $methodDTO], $workerId . static::getMethodName() . 'Resp');
    }

    /**
     * @param int $connectionId
     * @return TcpConnection|null
     */
    private static function getConnection(int $connectionId): ?TcpConnection
    {
        $connection = null;

        /** @var PoolConnectionInterface|null $poolClass */
        $poolClass = static::getPoolConnectionClass();
        if ($poolClass) {
            $connection = $poolClass::getConnection($connectionId) ?? static::warning('подключение не найдено.');
        }

        return $connection;
    }

    /**
     * @param string $warning
     * @return void
     */
    private static function warning(string $warning): void
    {
        echo static::getMethodName() . ": Предупреждение, {$warning}" . PHP_EOL;
    }

    protected static function defineCloseConnectionStrategy(): ?CloseConnectionStrategyInterface
    {
        return null;
    }

    private static function closeConnection(int $workerId, int $connectionId): void
    {
        static::defineCloseConnectionStrategy()
            ?->close($workerId, $connectionId);
    }

    /**
     * @param int $workerId
     * @param int $connectionId
     * @param MethodDTO $methodDTO
     * @return void
     */
    protected static function afterSendResponse(int $workerId, int $connectionId, MethodDTO $methodDTO): void
    {

    }

    /**
     * @param int $workerId
     * @param int $connectionId
     * @param MethodDTO $methodDTO
     * @return void
     */
    protected static function middleware(int $workerId, int $connectionId, MethodDTO $methodDTO): bool
    {
        return ApiKeyAuth::check($methodDTO);
    }

    private static function errorsFormated(ConstraintViolationListInterface &$errors): void
    {
        if ($errors->count()) {
            $formatted = [];

            foreach ($errors as $error) {
                $formatted[] = [
                    'field'   => $error->getPropertyPath(),
                    'message' => $error->getMessage(),
                ];
            }

            $errors = $formatted;
        }
    }

    private static function isNotValidated(?ConstraintViolationListInterface $errors): bool
    {
        if (is_null($errors)) {
            return false;
        }

        return $errors->count() > 0;
    }

    private static function errorsResponse(int $connectionId, MethodDTO $methodDTO, $errors): void
    {
        static::errorsFormated($errors);
        $methodDTO->response->errors = $errors;
        static::sendResponse($connectionId, $methodDTO->response, Error::class);
    }

    private static function isWithoutChannel(): bool
    {
        return is_null(static::getChannelClass());
    }

    private static function withoutChannelResponse(int $workerId, int $connectionId, MethodDTO $methodDTO): void
    {
        static::handlerResultProcess($workerId, $connectionId, $methodDTO);
        static::sendResponse($connectionId, $methodDTO->response);
        static::afterSendResponse($workerId, $connectionId, $methodDTO);
    }
}
