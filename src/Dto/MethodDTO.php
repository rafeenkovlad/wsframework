<?php

declare(strict_types=1);

namespace WsFramework\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class MethodDTO extends DataTransferObject
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Type('integer')]
        public ?int $id,
        #[Assert\NotBlank]
        public ?string $method,
        public array|DataTransferObject $params,
        #[Assert\Valid]
        public ?PayloadDTO $payload,
        public ?array $headers,
        #[Assert\Valid]
        public ?ResponseDTO $response,
    )
    {
    }

    protected static function dependedDTO(): array
    {
        return [
            'response' => ResponseDTO::class,
            'payload' => PayloadDTO::class,
        ];
    }

    protected static function dependedCollectionDTO(): array
    {
        return [];
    }

    protected static function getDefaultValues(): array
    {
        return [
            'id' => 0,
            'method' => 'defaultName',
            'params' => [],
            'response' => [],
            'payload' => [],
            'headers' => [],
        ];
    }

    public function getHeader(string $header): ?string
    {
        return $this->headers[$header] ?? null;
    }
}