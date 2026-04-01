<?php

/**
 * CLI: Export fingerprint configuration as JSON for Node.js warmup script.
 *
 * Usage: php scripts/export-fingerprint-config.php <fingerprint_name>
 *
 * Output: JSON with fingerprint properties, anti-detection JS, and Chrome launch args.
 */

declare(strict_types=1);

// Adjust CWD to project root (scripts/ is inside project root)
chdir(dirname(__DIR__));

require_once './vendor/autoload.php';

use WsFramework\Config\ENV;
use WsFramework\Pool\Browserless\PoolBrowserFingerprint;
use WsFramework\Service\Browserless\AntiDetectionScriptBuilder;

ENV::init();

$fingerprintName = $argv[1] ?? null;

if ($fingerprintName === null || $fingerprintName === '') {
    fwrite(STDERR, "Usage: php scripts/export-fingerprint-config.php <fingerprint_name>\n");
    fwrite(STDERR, "Available fingerprints: " . implode(', ', PoolBrowserFingerprint::getAvailableNames()) . "\n");
    exit(1);
}

if (!PoolBrowserFingerprint::exists($fingerprintName)) {
    fwrite(STDERR, "Unknown fingerprint: {$fingerprintName}\n");
    fwrite(STDERR, "Available: " . implode(', ', PoolBrowserFingerprint::getAvailableNames()) . "\n");
    exit(1);
}

$fingerprint = PoolBrowserFingerprint::getOffset($fingerprintName);

$viewportWidth = $fingerprint->viewportWidth ?? 1920;
$viewportHeight = $fingerprint->viewportHeight ?? 1080;
$stealthEnabled = filter_var($_ENV['BROWSERLESS_STEALTH_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

$builder = new AntiDetectionScriptBuilder($fingerprint, $viewportWidth, $viewportHeight, $stealthEnabled);
$antiDetectionJs = $builder->build();

$launchArgs = [
    '--disable-blink-features=AutomationControlled',
    '--disable-features=site-per-process',
    '--disable-dev-shm-usage',
    '--no-first-run',
    '--disable-default-apps',
    '--disable-component-update',
    '--disable-background-networking',
    '--no-sandbox',
];

$result = [
    'fingerprint' => [
        'userAgent' => $fingerprint->userAgent,
        'acceptLanguage' => $fingerprint->acceptLanguage,
        'platform' => $fingerprint->platform,
        'viewportWidth' => $viewportWidth,
        'viewportHeight' => $viewportHeight,
        'webglVendor' => $fingerprint->webglVendor,
        'webglRenderer' => $fingerprint->webglRenderer,
        'hardwareConcurrency' => $fingerprint->hardwareConcurrency,
        'deviceMemory' => $fingerprint->deviceMemory,
        'screenWidth' => $fingerprint->screenWidth,
        'screenHeight' => $fingerprint->screenHeight,
    ],
    'antiDetectionJs' => $antiDetectionJs,
    'launchArgs' => $launchArgs,
    'stealthEnabled' => $stealthEnabled,
];

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
