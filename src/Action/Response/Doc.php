<?php

namespace WsFramework\Action\Response;

use WsFramework\Dto\ResponseDTO;
use Workerman\Connection\TcpConnection;

class Doc
{
    public static function apply(TcpConnection $connection, ResponseDTO $responseDTO): void
    {
        $connection->headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ];
        $connection->send(json_encode($responseDTO->result['doc']));
        unset($responseDTO);
    }
}
