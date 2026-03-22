<?php

declare(strict_types=1);

namespace WsFramework\Middleware;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\MethodDTO;

class MethodParamsToDTO
{
    public static function main(MethodDTO $methodDTO, string $paramsClassDTO): void
    {
        /** @var  DataTransferObject $paramsClassDTO */
        $methodDTO->params = $paramsClassDTO::createFromArray($methodDTO->params);
    }
}