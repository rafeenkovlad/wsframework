<?php

namespace WsFramework\Action\Method;

use PSX\OpenRPC\ContentDescriptor;
use PSX\OpenRPC\Method;

abstract class MethodOpenRPCAbstract
{
    protected static function getContentDescriptor(): ContentDescriptor
    {
        return new ContentDescriptor();
    }

    protected static function getSchemaResponse(): ContentDescriptor
    {
        $result = static::getContentDescriptor();
        $result->setName('response');
        $result->setSchema([
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                ],
                'response' => [
                    'type' => 'string',
                ],
                'fromMethod' => [
                    'type' => 'string',
                ],
                'result' => static::getResult() ?? [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ],
        ]);

        return $result;
    }

    abstract protected static function getDescription(): string;
    abstract protected static function getSchemaArgsDescriptor(): array;
    abstract protected static function getResult(): ?array;

    public static function toOpenRpcMethod(): Method
    {
        $m = new Method();
        $m->setName(static::getMethodName());
        $m->setSummary(static::getDescription());
        $m->setParams(static::getSchemaArgsDescriptor());
        $m->setResult(static::getSchemaResponse());
//        $method = MethodDTO::createFromArray(
//            [
//                'id' => 1,
//                'method' => static::getMethodName(),
//                'params' => static::getSchemaArgsDescriptor()
//            ]
//        );
//        $response = ResponseDTO::createFromArray(
//            [
//                'id' => 1,
//                'response' => static::getResponseClass()::getResponseName(),
//                'fromMethod' => static::getMethodName(),
//                'result' => static::getResult(),
//            ],
//        );
//        $m->setExamples(['name' => $method->method, 'params' => $method->params, 'result' => $response]);

        return $m;
    }
}
