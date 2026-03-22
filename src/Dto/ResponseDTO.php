<?php

declare(strict_types=1);

namespace WsFramework\Dto;

class ResponseDTO extends DataTransferObject
{
    public function __construct(
        public ?int $id,
        public ?string $response,
        public ?string $fromMethod,
        public array|DataTransferObject $result,
        public array $errors,
    )
    {
    }

    protected static function dependedDTO(): array
    {
        return [];
    }

    protected static function dependedCollectionDTO(): array
    {
        return [];
    }

    /**
     * @return array
     */
    protected static function getDefaultValues(): array
    {
        return [
            'id' => null,
            'response' => null,
            'fromMethod' => null,
            'result' => [],
            'errors' => [],
        ];
    }
}