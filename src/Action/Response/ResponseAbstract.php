<?php

namespace WsFramework\Action\Response;

use WsFramework\Dto\ResponseDTO;
use Workerman\Connection\TcpConnection;

abstract class ResponseAbstract
{

    protected static function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
    }
    /**
     * @param TcpConnection $connection
     * @param ResponseDTO $responseDTO
     * @return void
     */
    public static function apply(TcpConnection $connection, ResponseDTO $responseDTO): void
    {
        $responseDTO->response = static::getResponseName();
        if (method_exists(static::class, 'headers')) {
            $connection->headers = static::headers();
        }
        $connection->send($responseDTO->jsonEncode());
        unset($responseDTO);
    }

    /**
     * @return string
     */
    abstract public static function getResponseName(): string;
}
