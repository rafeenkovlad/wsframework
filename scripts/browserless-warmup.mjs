#!/usr/bin/env node

/**
 * Browserless Cookie Warmup Script
 *
 * Launches Chrome with a persistent profile for manual cookie warming.
 * Connect via chrome://inspect to browse sites and build up cookies/localStorage.
 *
 * Cookies are stored in the Chrome profile (SQLite) automatically.
 * PHP reads them directly via CookieStorageProvider.
 *
 * Usage:
 *   node scripts/browserless-warmup.mjs --fingerprint chrome_win10
 */

import { execSync, spawn } from 'child_process';
import { mkdirSync, existsSync } from 'fs';
import puppeteer from 'puppeteer-core';

const PROJECT_ROOT = '/var/www/html';

function parseArgs() {
    const args = process.argv.slice(2);
    const parsed = {};

    for (let i = 0; i < args.length; i++) {
        if (args[i] === '--fingerprint' && args[i + 1]) {
            parsed.fingerprint = args[i + 1];
            i++;
        }
    }

    return parsed;
}

async function main() {
    const args = parseArgs();

    if (!args.fingerprint) {
        console.error('Usage: node scripts/browserless-warmup.mjs --fingerprint <name>');
        console.error('Example: node scripts/browserless-warmup.mjs --fingerprint chrome_win10');
        process.exit(1);
    }

    const fingerprint = args.fingerprint;

    // Export fingerprint config via PHP
    let config;
    try {
        const phpOutput = execSync(
            `php ${PROJECT_ROOT}/scripts/export-fingerprint-config.php ${fingerprint}`,
            { encoding: 'utf-8', cwd: PROJECT_ROOT }
        );
        config = JSON.parse(phpOutput);
    } catch (err) {
        console.error(`Failed to export fingerprint config: ${err.message}`);
        process.exit(1);
    }

    const profileDir = `${PROJECT_ROOT}/storage/profiles/${fingerprint}`;

    // Ensure profile directory exists
    if (!existsSync(profileDir)) {
        mkdirSync(profileDir, { recursive: true });
    }

    // Launch Chrome directly with persistent profile
    const launchArgs = [
        ...config.launchArgs,
        `--user-data-dir=${profileDir}`,
        '--remote-debugging-port=9333',
        // Prevent Chrome from throttling/hiding backgrounded pages
        '--disable-backgrounding-occluded-windows',
        '--disable-renderer-backgrounding',
        '--disable-background-timer-throttling',
        '--disable-ipc-flooding-protection',
    ];

    const browser = await puppeteer.launch({
        headless: false,
        executablePath: '/usr/bin/google-chrome',
        pipe: true,
        args: launchArgs,
        defaultViewport: {
            width: config.fingerprint.viewportWidth,
            height: config.fingerprint.viewportHeight,
        },
        ignoreDefaultArgs: ['--enable-automation', '--remote-debugging-port'],
        handleSIGINT: false,
        handleSIGTERM: false,
    });

    // Visibility override script — patches Document.prototype so it survives navigations
    const visibilityOverrideJs = `
        Object.defineProperty(Document.prototype, 'visibilityState', { get: () => 'visible', configurable: true });
        Object.defineProperty(Document.prototype, 'hidden', { get: () => false, configurable: true });
        Document.prototype.hasFocus = () => true;
        window.addEventListener('visibilitychange', (e) => { e.stopImmediatePropagation(); e.stopPropagation(); }, true);
        window.addEventListener('blur', (e) => { e.stopImmediatePropagation(); e.stopPropagation(); }, true);
        window.addEventListener('focus', (e) => { e.stopImmediatePropagation(); e.stopPropagation(); }, true);
        window.dispatchEvent(new Event('focus'));
    `;

    /**
     * Apply anti-detection + visibility overrides to a page via CDP.
     * Uses Emulation.setFocusEmulationEnabled so Chrome itself treats the page as focused.
     */
    async function setupPage(targetPage) {
        try {
            const cdp = await targetPage.createCDPSession();

            // Tell Chrome at protocol level that this page is focused
            await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true });

            // Inject scripts that run on every new document (including navigations)
            await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: config.antiDetectionJs });
            await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source: visibilityOverrideJs });

            // Also apply to the current document immediately
            await targetPage.evaluate(new Function(visibilityOverrideJs)).catch(() => {});

            await cdp.detach();
        } catch {
            // Target might be closed or a service worker — ignore
        }
    }

    // Setup initial page
    const pages = await browser.pages();
    const page = pages[0] || await browser.newPage();
    await setupPage(page);

    // Auto-setup any new tabs/pages opened via DevTools
    browser.on('targetcreated', async (target) => {
        if (target.type() === 'page') {
            try {
                const newPage = await target.page();
                if (newPage) {
                    await setupPage(newPage);
                }
            } catch {
                // Target might have closed before we could set it up
            }
        }
    });

    // Set UA and headers on initial page
    await page.setUserAgent(config.fingerprint.userAgent);
    await page.setExtraHTTPHeaders({
        'Accept-Language': config.fingerprint.acceptLanguage,
    });

    // Navigate to blank page to confirm session is ready
    await page.goto('about:blank');

    // Proxy DevTools port to 0.0.0.0 so it's accessible outside the container
    const socat = spawn('socat', ['TCP-LISTEN:9222,fork,reuseaddr,bind=0.0.0.0', 'TCP:127.0.0.1:9333'], {
        stdio: 'ignore',
    });

    console.log(`\nWarmup session started for fingerprint: ${fingerprint}`);
    console.log(`Profile directory: ${profileDir}`);
    console.log('Cookies are stored in Chrome profile (SQLite) — no JSON export needed.');
    console.log('');
    console.log('Connect via Chrome DevTools:');
    console.log('  1. Open chrome://inspect in your local Chrome');
    console.log('  2. Configure → add localhost:9222 → Done');
    console.log('  3. Click "inspect" on the Remote Target');
    console.log('');
    console.log('Browse sites to warm up cookies (google.com, youtube.com, etc.)');
    console.log('Press Ctrl+C to stop.\n');

    // Handle graceful shutdown
    let shuttingDown = false;

    const shutdown = async () => {
        if (shuttingDown) return;
        shuttingDown = true;

        console.log('\nShutting down warmup session...');

        try {
            await browser.close();
        } catch {
            // Browser might already be closed
        }

        socat.kill();

        console.log(`Cookies saved in Chrome profile: ${profileDir}/Default/Cookies`);
        console.log('PHP will read them directly via CookieStorageProvider.');

        process.exit(0);
    };

    process.on('SIGINT', shutdown);
    process.on('SIGTERM', shutdown);

    // Keep the process alive
    await new Promise(() => {});
}

main().catch((err) => {
    console.error(`Fatal error: ${err.message}`);
    process.exit(1);
});
