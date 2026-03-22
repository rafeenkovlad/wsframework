<?php

declare(strict_types=1);

namespace WsFramework\Service\HelpService\InjectionContainerService;

use Symfony\Component\DependencyInjection\ContainerInterface;

final class NatsJetstreamServiceSymfonyContainerFactory
{
    public static function boot(string $configDir): ContainerInterface
    {
        return WorkerContainerFactory::boot(
            'natsJetstream',
            $configDir,
            [
                'services/nats_jetstream.yaml',
            ],
        );
    }
}
