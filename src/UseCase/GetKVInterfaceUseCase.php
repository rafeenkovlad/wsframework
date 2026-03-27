<?php

namespace WsFramework\UseCase;

use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Dto\DataTransferObject;

class GetKVInterfaceUseCase extends AbstractUseCase
{

    private NatsKeyValueInterface $kv;

    /**
     * @param DataTransferObject|null $DTO
     * @param ...$args
     * @return NatsKeyValueInterface
     */
    public static function handle(?DataTransferObject $DTO = null, ...$args): NatsKeyValueInterface
    {
        return static::create()->initKVInterface()->getKvInterface();
    }

    /**
     * @return $this
     */
    private function initKVInterface(): static
    {
        $this->kv ??= KVNatsBucket::eventInterface()->bucket('ffmpeg_jobs_status');

        return $this;
    }

    /**
     * @return NatsKeyValueInterface
     */
    private function getKvInterface(): NatsKeyValueInterface
    {
        return $this->kv;
    }
}