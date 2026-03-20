<?php

declare(strict_types=1);

namespace WsFramework\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class FfmpegJobParamsDTO extends DataTransferObject
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $inputFile,
        #[Assert\NotBlank]
        public ?string $outputFile,
        public ?array  $options,
        public ?int    $priority,
    ) {
    }

    protected static function dependedDTO(): array
    {
        return [];
    }

    protected static function dependedCollectionDTO(): array
    {
        return [];
    }

    protected static function getDefaultValues(): array
    {
        return [
            'inputFile' => null,
            'outputFile' => null,
            'options' => [],
            'priority' => 10,
        ];
    }
}
