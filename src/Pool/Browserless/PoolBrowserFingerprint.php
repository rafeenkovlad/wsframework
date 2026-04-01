<?php

declare(strict_types=1);

namespace WsFramework\Pool\Browserless;

use RuntimeException;
use WsFramework\Dto\Pool\BrowserFingerprintDTO;
use WsFramework\Pool\PoolAbstract;

class PoolBrowserFingerprint extends PoolAbstract
{
    private static bool $initialized = false;

    public static function getCollectionName(): string
    {
        return 'browser_fingerprint_collection';
    }

    public static function getOffset(int|string $offset): ?BrowserFingerprintDTO
    {
        static::ensureInitialized();

        return parent::getOffset($offset);
    }

    public static function exists(string $name): bool
    {
        static::ensureInitialized();

        return parent::getOffset($name) !== null;
    }

    /**
     * @return list<string>
     */
    public static function getAvailableNames(): array
    {
        static::ensureInitialized();

        return array_keys(static::defaultProfiles());
    }

    /**
     * @throws RuntimeException
     */
    public static function getRandomName(): string
    {
        $names = static::getAvailableNames();
        if ($names === []) {
            throw new RuntimeException('Fingerprint collection is empty');
        }

        return $names[array_rand($names)];
    }

    /**
     * JS для anti-detection: скрытие webdriver, фейковые plugins/languages/platform.
     *
     * @deprecated Use AntiDetectionScriptBuilder instead for comprehensive anti-detection.
     */
    public static function getAntiDetectionScript(string $platform, string $acceptLanguage): string
    {
        $languages = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $lang = trim(explode(';', $part)[0]);
            if ($lang !== '') {
                $languages[] = $lang;
            }
        }
        $languagesJson = json_encode($languages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $platformEscaped = addslashes($platform);

        return <<<JS
        (function() {
            Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
            delete navigator.__proto__.webdriver;

            Object.defineProperty(navigator, 'plugins', {
                get: () => {
                    const plugins = [
                        {name: 'Chrome PDF Plugin', filename: 'internal-pdf-viewer', description: 'Portable Document Format', length: 1},
                        {name: 'Chrome PDF Viewer', filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai', description: '', length: 1},
                        {name: 'Native Client', filename: 'internal-nacl-plugin', description: '', length: 2}
                    ];
                    plugins.refresh = () => {};
                    return plugins;
                }
            });

            Object.defineProperty(navigator, 'languages', {get: () => {$languagesJson}});
            Object.defineProperty(navigator, 'platform', {get: () => '{$platformEscaped}'});

            if (!window.chrome) {
                window.chrome = {};
            }
            window.chrome.runtime = window.chrome.runtime || {};
            window.chrome.loadTimes = window.chrome.loadTimes || function() { return {}; };
            window.chrome.csi = window.chrome.csi || function() { return {}; };

            const originalQuery = window.navigator.permissions.query;
            window.navigator.permissions.query = (parameters) => (
                parameters.name === 'notifications'
                    ? Promise.resolve({state: Notification.permission})
                    : originalQuery(parameters)
            );
        })();
        JS;
    }

    protected static function getClassDTO(): string
    {
        return BrowserFingerprintDTO::class;
    }

    private static function ensureInitialized(): void
    {
        if (static::$initialized) {
            return;
        }

        foreach (static::defaultProfiles() as $name => $profile) {
            static::addOffset($name);
            $dto = parent::getOffset($name);
            $dto->userAgent = $profile['userAgent'];
            $dto->acceptLanguage = $profile['acceptLanguage'];
            $dto->platform = $profile['platform'];
            $dto->viewportWidth = $profile['viewportWidth'] ?? null;
            $dto->viewportHeight = $profile['viewportHeight'] ?? null;
            $dto->webglVendor = $profile['webglVendor'] ?? null;
            $dto->webglRenderer = $profile['webglRenderer'] ?? null;
            $dto->hardwareConcurrency = $profile['hardwareConcurrency'] ?? null;
            $dto->deviceMemory = $profile['deviceMemory'] ?? null;
            $dto->screenWidth = $profile['screenWidth'] ?? null;
            $dto->screenHeight = $profile['screenHeight'] ?? null;
            $dto->screenAvailWidth = $profile['screenAvailWidth'] ?? null;
            $dto->screenAvailHeight = $profile['screenAvailHeight'] ?? null;
            $dto->screenColorDepth = $profile['screenColorDepth'] ?? null;
            $dto->connectionType = $profile['connectionType'] ?? null;
            $dto->connectionRtt = $profile['connectionRtt'] ?? null;
            $dto->connectionDownlink = $profile['connectionDownlink'] ?? null;

            static::validateProfile($name, $dto);
        }

        static::$initialized = true;
    }

    private static function validateProfile(string $name, BrowserFingerprintDTO $dto): void
    {
        if (
            $dto->screenWidth !== null
            && $dto->viewportWidth !== null
            && $dto->screenWidth < $dto->viewportWidth
        ) {
            throw new RuntimeException(
                "Fingerprint '{$name}': screenWidth ({$dto->screenWidth}) < viewportWidth ({$dto->viewportWidth})",
            );
        }

        if (
            $dto->screenHeight !== null
            && $dto->viewportHeight !== null
            && $dto->screenHeight < $dto->viewportHeight
        ) {
            throw new RuntimeException(
                "Fingerprint '{$name}': screenHeight ({$dto->screenHeight}) < viewportHeight ({$dto->viewportHeight})",
            );
        }

        if ($dto->screenAvailWidth !== null && $dto->screenWidth !== null && $dto->screenAvailWidth > $dto->screenWidth) {
            throw new RuntimeException(
                "Fingerprint '{$name}': screenAvailWidth ({$dto->screenAvailWidth}) > screenWidth ({$dto->screenWidth})",
            );
        }

        if ($dto->screenAvailHeight !== null && $dto->screenHeight !== null && $dto->screenAvailHeight > $dto->screenHeight) {
            throw new RuntimeException(
                "Fingerprint '{$name}': screenAvailHeight ({$dto->screenAvailHeight}) > screenHeight ({$dto->screenHeight})",
            );
        }

        if ($dto->webglVendor !== null && $dto->platform !== null) {
            static::validateWebglPlatformConsistency($name, $dto->platform, $dto->webglVendor, $dto->webglRenderer);
        }
    }

    private static function validateWebglPlatformConsistency(
        string $name,
        string $platform,
        string $webglVendor,
        ?string $webglRenderer,
    ): void {
        $isApplePlatform = in_array($platform, ['MacIntel', 'iPhone'], true);
        $isWindowsPlatform = $platform === 'Win32';
        $hasDirectX = $webglRenderer !== null && str_contains($webglRenderer, 'Direct3D');

        if ($isApplePlatform && !str_contains($webglVendor, 'Apple')) {
            throw new RuntimeException(
                "Fingerprint '{$name}': Apple platform '{$platform}' expects Apple WebGL vendor, got '{$webglVendor}'",
            );
        }

        if ($isWindowsPlatform && $hasDirectX === false && $webglRenderer !== null) {
            throw new RuntimeException(
                "Fingerprint '{$name}': Win32 platform expects Direct3D WebGL renderer, got '{$webglRenderer}'",
            );
        }

        if (!$isApplePlatform && $webglVendor === 'Apple') {
            throw new RuntimeException(
                "Fingerprint '{$name}': non-Apple platform '{$platform}' has Apple WebGL vendor",
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function defaultProfiles(): array
    {
        return [
            'chrome_win10' => [
                'userAgent'           => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'acceptLanguage'      => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'platform'            => 'Win32',
                'webglVendor'         => 'Google Inc. (NVIDIA)',
                'webglRenderer'       => 'ANGLE (NVIDIA, NVIDIA GeForce GTX 1660 SUPER Direct3D11 vs_5_0 ps_5_0, D3D11)',
                'hardwareConcurrency' => 6,
                'deviceMemory'        => 8,
                'screenWidth'         => 1920,
                'screenHeight'        => 1080,
                'screenAvailWidth'    => 1920,
                'screenAvailHeight'   => 1040,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 50,
                'connectionDownlink'  => 10.0,
            ],
            'chrome_win11' => [
                'userAgent'           => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36',
                'acceptLanguage'      => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'platform'            => 'Win32',
                'webglVendor'         => 'Google Inc. (NVIDIA)',
                'webglRenderer'       => 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3060 Direct3D11 vs_5_0 ps_5_0, D3D11)',
                'hardwareConcurrency' => 8,
                'deviceMemory'        => 8,
                'screenWidth'         => 2560,
                'screenHeight'        => 1440,
                'screenAvailWidth'    => 2560,
                'screenAvailHeight'   => 1392,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 50,
                'connectionDownlink'  => 10.0,
            ],
            'chrome_macos' => [
                'userAgent'           => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'acceptLanguage'      => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'platform'            => 'MacIntel',
                'webglVendor'         => 'Google Inc. (Apple)',
                'webglRenderer'       => 'ANGLE (Apple, Apple M1, OpenGL 4.1)',
                'hardwareConcurrency' => 8,
                'deviceMemory'        => 8,
                'screenWidth'         => 1920,
                'screenHeight'        => 1080,
                'screenAvailWidth'    => 1920,
                'screenAvailHeight'   => 1055,
                'screenColorDepth'    => 30,
                'connectionType'      => '4g',
                'connectionRtt'       => 50,
                'connectionDownlink'  => 10.0,
            ],
            'chrome_linux' => [
                'userAgent'           => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'acceptLanguage'      => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'platform'            => 'Linux x86_64',
                'webglVendor'         => 'Google Inc. (Intel)',
                'webglRenderer'       => 'ANGLE (Intel, Mesa Intel(R) UHD Graphics 630 (CFL GT2), OpenGL 4.6)',
                'hardwareConcurrency' => 8,
                'deviceMemory'        => 8,
                'screenWidth'         => 1920,
                'screenHeight'        => 1080,
                'screenAvailWidth'    => 1920,
                'screenAvailHeight'   => 1052,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 50,
                'connectionDownlink'  => 10.0,
            ],
            'firefox_win10' => [
                'userAgent'           => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
                'acceptLanguage'      => 'ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
                'platform'            => 'Win32',
                'webglVendor'         => 'Google Inc. (NVIDIA)',
                'webglRenderer'       => 'ANGLE (NVIDIA, NVIDIA GeForce GTX 1660 SUPER Direct3D11 vs_5_0 ps_5_0, D3D11)',
                'hardwareConcurrency' => 6,
                'deviceMemory'        => 8,
                'screenWidth'         => 1920,
                'screenHeight'        => 1080,
                'screenAvailWidth'    => 1920,
                'screenAvailHeight'   => 1040,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 50,
                'connectionDownlink'  => 10.0,
            ],
            'safari_macos' => [
                'userAgent'           => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
                'acceptLanguage'      => 'ru-RU,ru;q=0.9',
                'platform'            => 'MacIntel',
                'webglVendor'         => 'Apple',
                'webglRenderer'       => 'Apple M1',
                'hardwareConcurrency' => 8,
                'deviceMemory'        => 8,
                'screenWidth'         => 1920,
                'screenHeight'        => 1080,
                'screenAvailWidth'    => 1920,
                'screenAvailHeight'   => 1055,
                'screenColorDepth'    => 30,
                'connectionType'      => '4g',
                'connectionRtt'       => 50,
                'connectionDownlink'  => 10.0,
            ],
            'chrome_android' => [
                'userAgent'           => 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.6778.135 Mobile Safari/537.36',
                'acceptLanguage'      => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'platform'            => 'Linux armv81',
                'viewportWidth'       => 412,
                'viewportHeight'      => 915,
                'webglVendor'         => 'Qualcomm',
                'webglRenderer'       => 'Adreno (TM) 740',
                'hardwareConcurrency' => 8,
                'deviceMemory'        => 8,
                'screenWidth'         => 412,
                'screenHeight'        => 915,
                'screenAvailWidth'    => 412,
                'screenAvailHeight'   => 891,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 100,
                'connectionDownlink'  => 5.65,
            ],
            'safari_iphone' => [
                'userAgent'           => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
                'acceptLanguage'      => 'ru-RU,ru;q=0.9',
                'platform'            => 'iPhone',
                'viewportWidth'       => 390,
                'viewportHeight'      => 844,
                'webglVendor'         => 'Apple',
                'webglRenderer'       => 'Apple GPU',
                'hardwareConcurrency' => 6,
                'deviceMemory'        => 4,
                'screenWidth'         => 390,
                'screenHeight'        => 844,
                'screenAvailWidth'    => 390,
                'screenAvailHeight'   => 844,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 100,
                'connectionDownlink'  => 5.65,
            ],
            'edge_win11' => [
                'userAgent'           => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.2903.86',
                'acceptLanguage'      => 'ru,en;q=0.9,en-GB;q=0.8,en-US;q=0.7',
                'platform'            => 'Win32',
                'webglVendor'         => 'Google Inc. (AMD)',
                'webglRenderer'       => 'ANGLE (AMD, AMD Radeon RX 580 Direct3D11 vs_5_0 ps_5_0, D3D11)',
                'hardwareConcurrency' => 8,
                'deviceMemory'        => 8,
                'screenWidth'         => 1920,
                'screenHeight'        => 1080,
                'screenAvailWidth'    => 1920,
                'screenAvailHeight'   => 1032,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 50,
                'connectionDownlink'  => 10.0,
            ],
            'yandex_win10' => [
                'userAgent'           => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 YaBrowser/24.12.0.0 Safari/537.36',
                'acceptLanguage'      => 'ru,en;q=0.9',
                'platform'            => 'Win32',
                'webglVendor'         => 'Google Inc. (Intel)',
                'webglRenderer'       => 'ANGLE (Intel, Intel(R) UHD Graphics 630 Direct3D11 vs_5_0 ps_5_0, D3D11)',
                'hardwareConcurrency' => 4,
                'deviceMemory'        => 8,
                'screenWidth'         => 1366,
                'screenHeight'        => 768,
                'screenAvailWidth'    => 1366,
                'screenAvailHeight'   => 728,
                'screenColorDepth'    => 24,
                'connectionType'      => '4g',
                'connectionRtt'       => 100,
                'connectionDownlink'  => 7.2,
            ],
        ];
    }
}
