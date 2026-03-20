<?php

declare(strict_types=1);

namespace WsFramework\Service\HelpService\InjectionContainerService;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class WorkerContainerFactory
{
    /**
     * @param non-empty-string $containerName
     * @param string[] $configFiles
     * @param array<string, object> $runtimeServices
     */
    public static function boot(
        string $containerName,
        string $configDir,
        array $configFiles,
        array $runtimeServices = [],
    ): ContainerInterface
    {
        $className = static::containerClassName($containerName, $configDir, $configFiles, $runtimeServices);
        $cache = static::containerCache($configDir, $className);

        if (!$cache->isFresh()) {
            $container = static::buildContainer($configDir, $configFiles, $runtimeServices);
            $cache->write(
                (new PhpDumper($container))->dump(['class' => $className]),
                $container->getResources(),
            );
        }

        require_once $cache->getPath();

        /** @var ContainerInterface $container */
        $container = new $className();

        foreach ($runtimeServices as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    private static function buildContainer(string $configDir, array $configFiles, array $runtimeServices): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator($configDir));

        foreach ($configFiles as $configFile) {
            $loader->load($configFile);
        }

        static::registerRuntimeServiceDefinitions($container, $runtimeServices);
        $container->compile();

        return $container;
    }

    /**
     * @param array<string, object> $runtimeServices
     */
    private static function registerRuntimeServiceDefinitions(ContainerBuilder $container, array $runtimeServices): void
    {
        foreach ($runtimeServices as $id => $service) {
            if ($container->has($id) || $container->hasAlias($id)) {
                continue;
            }

            $container->register($id, get_class($service))
                ->setSynthetic(true)
                ->setPublic(true);
        }
    }

    /**
     * @param string[] $configFiles
     * @param array<string, object> $runtimeServices
     * @return non-empty-string
     */
    private static function containerClassName(
        string $containerName,
        string $configDir,
        array $configFiles,
        array $runtimeServices,
    ): string
    {
        $normalizedName = preg_replace('/[^A-Za-z0-9_]/', '_', $containerName) ?: 'Worker';
        $signature = substr(hash('xxh128', json_encode([
            $configFiles,
            array_keys($runtimeServices),
            static::configSignature($configDir),
        ])), 0, 12);

        return ucfirst($normalizedName) . $signature . 'Container';
    }

    private static function configSignature(string $configDir): string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($configDir, \FilesystemIterator::SKIP_DOTS),
        );
        $signature = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'yaml') {
                continue;
            }

            $signature[] = [
                $file->getPathname(),
                $file->getMTime(),
                $file->getSize(),
            ];
        }

        sort($signature);

        return hash('xxh128', json_encode($signature));
    }

    private static function containerCache(string $configDir, string $className): ConfigCache
    {
        $projectDir = dirname(rtrim($configDir, '/'));
        $cacheDir = $projectDir . '/tmp/di';

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('Unable to create container cache directory: %s', $cacheDir));
        }

        return new ConfigCache($cacheDir . '/' . $className . '.php', static::isDebug());
    }

    private static function isDebug(): bool
    {
        return filter_var(
            $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false,
            \FILTER_VALIDATE_BOOL,
        );
    }
}
