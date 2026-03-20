<?php

declare(strict_types=1);

namespace WsFramework\Service\HelpService\InjectionContainerService;

use Symfony\Component\DependencyInjection\ContainerInterface;

final class FfmpegQueueServiceSymfonyContainerFactory
{
    public static function boot(string $configDir): ContainerInterface
    {
        return WorkerContainerFactory::boot(
            'ffmpegQueue',
            $configDir,
            [
                'services/ffmpeg_queue.yaml',
            ],
        );
    }
}
