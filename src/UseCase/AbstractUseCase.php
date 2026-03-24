<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\Pool\UseCaseDTO;
use WsFramework\Pool\UseCase\PoolUseCase;

abstract class AbstractUseCase
{
    private function __construct(protected DataTransferObject $DTO, ...$args)
    {
        foreach ($args as $key => $arg) {
            $this->{$key} = $arg;
        }
    }

    /**
     * @param DataTransferObject $DTO
     * @return static
     */
    protected static function create(DataTransferObject $DTO): static
    {
        if (! PoolUseCase::getOffset(static::class)) {
            PoolUseCase::addOffset(static::class);
        }

        if (! PoolUseCase::getOffset(static::class)->useCase instanceof static) {
            PoolUseCase::getOffset(static::class)->useCase = new static($DTO);
        } else {
            PoolUseCase::getOffset(static::class)->useCase->DTO = $DTO;
        }

        return PoolUseCase::getOffset(static::class)->useCase;
    }

    /**
     * @return static|null
     */
    protected static function getInstancedUseCase(): ?static
    {
        /** @var UseCaseDTO|null $static */
        $static = PoolUseCase::getOffset(static::class);
        return $static?->useCase;
    }

    abstract public static function handle(DataTransferObject $DTO, ...$args);
}