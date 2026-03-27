<?php

namespace WsFramework\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\defaultDTO;
use WsFramework\Enum\Pipeline;

class DefineCurrentPipelineUseCase extends AbstractUseCase
{
    private Pipeline $pipeline;

    /**
     * @param defaultDTO|null $DTO
     * @param ...$args
     * @return Pipeline
     */
    public static function handle(?DataTransferObject $DTO = null, ...$args): Pipeline
    {
        return static::create($DTO)->execute();
    }

    private function execute(): Pipeline
    {
        /** @var defaultDTO $DTO */
        $DTO = $this->DTO;
        $this->pipeline ??= $DTO?->pipeline;

        return $this->pipeline;
    }
}