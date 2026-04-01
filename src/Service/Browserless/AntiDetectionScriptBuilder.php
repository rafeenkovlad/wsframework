<?php

declare(strict_types=1);

namespace WsFramework\Service\Browserless;

use WsFramework\Dto\Pool\BrowserFingerprintDTO;

class AntiDetectionScriptBuilder
{
    public function __construct(
        private readonly BrowserFingerprintDTO $fingerprint,
        private readonly int $viewportWidth = 1920,
        private readonly int $viewportHeight = 1080,
        private readonly bool $stealthEnabled = true,
    ) {
    }

    public function build(): string
    {
        $parts = [
            $this->buildNavigatorOverrides(),
            $this->buildPluginOverrides(),
            $this->buildWebGLOverrides(),
            $this->buildCanvasNoiseInjection(),
            $this->buildScreenOverrides(),
            $this->buildConnectionOverrides(),
            $this->buildPermissionsOverrides(),
        ];

        if (!$this->stealthEnabled) {
            $parts[] = $this->buildChromeObjectOverrides();
        }

        $body = implode("\n", array_filter($parts, static fn(string $s) => $s !== ''));

        return "(function() {\n{$body}\n})();";
    }

    private function buildNavigatorOverrides(): string
    {
        $fp = $this->fingerprint;

        $languages = [];
        if ($fp->acceptLanguage !== null) {
            foreach (explode(',', $fp->acceptLanguage) as $part) {
                $lang = trim(explode(';', $part)[0]);
                if ($lang !== '') {
                    $languages[] = $lang;
                }
            }
        }
        $languagesJson = json_encode($languages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $platformEscaped = addslashes($fp->platform ?? 'Win32');

        $js = <<<JS
            Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
            try { delete navigator.__proto__.webdriver; } catch(e) {}
            Object.defineProperty(navigator, 'languages', {get: () => {$languagesJson}});
            Object.defineProperty(navigator, 'platform', {get: () => '{$platformEscaped}'});
        JS;

        if ($fp->hardwareConcurrency !== null) {
            $js .= "\n    Object.defineProperty(navigator, 'hardwareConcurrency', {get: () => {$fp->hardwareConcurrency}});";
        }

        if ($fp->deviceMemory !== null) {
            $js .= "\n    Object.defineProperty(navigator, 'deviceMemory', {get: () => {$fp->deviceMemory}});";
        }

        return $js;
    }

    private function buildPluginOverrides(): string
    {
        return <<<'JS'
            Object.defineProperty(navigator, 'plugins', {
                get: () => {
                    const makeMimeType = (type, suffixes, description, plugin) => {
                        const mt = Object.create(MimeType.prototype);
                        Object.defineProperties(mt, {
                            type: {get: () => type},
                            suffixes: {get: () => suffixes},
                            description: {get: () => description},
                            enabledPlugin: {get: () => plugin}
                        });
                        return mt;
                    };

                    const makePlugin = (name, description, filename, mimeTypes) => {
                        const p = Object.create(Plugin.prototype);
                        const mimes = mimeTypes.map(m => makeMimeType(m.type, m.suffixes, m.description, p));
                        Object.defineProperties(p, {
                            name: {get: () => name},
                            description: {get: () => description},
                            filename: {get: () => filename},
                            length: {get: () => mimes.length}
                        });
                        mimes.forEach((m, i) => {
                            Object.defineProperty(p, i, {get: () => m, enumerable: false});
                        });
                        p.item = (idx) => mimes[idx] || null;
                        p.namedItem = (name) => mimes.find(m => m.type === name) || null;
                        p[Symbol.iterator] = function*() { yield* mimes; };
                        return p;
                    };

                    const plugins = [
                        makePlugin(
                            'PDF Viewer', 'Portable Document Format', 'internal-pdf-viewer',
                            [{type: 'application/pdf', suffixes: 'pdf', description: 'Portable Document Format'}]
                        ),
                        makePlugin(
                            'Chrome PDF Viewer', '', 'internal-pdf-viewer',
                            [{type: 'application/pdf', suffixes: 'pdf', description: 'Portable Document Format'}]
                        ),
                        makePlugin(
                            'Chromium PDF Viewer', '', 'internal-pdf-viewer',
                            [{type: 'application/pdf', suffixes: 'pdf', description: 'Portable Document Format'}]
                        ),
                        makePlugin(
                            'Microsoft Edge PDF Viewer', '', 'internal-pdf-viewer',
                            [{type: 'application/pdf', suffixes: 'pdf', description: 'Portable Document Format'}]
                        ),
                        makePlugin(
                            'WebKit built-in PDF', '', 'internal-pdf-viewer',
                            [{type: 'application/pdf', suffixes: 'pdf', description: 'Portable Document Format'}]
                        )
                    ];

                    const arr = Object.create(PluginArray.prototype);
                    Object.defineProperty(arr, 'length', {get: () => plugins.length});
                    plugins.forEach((p, i) => {
                        Object.defineProperty(arr, i, {get: () => p, enumerable: false});
                    });
                    arr.item = (idx) => plugins[idx] || null;
                    arr.namedItem = (name) => plugins.find(p => p.name === name) || null;
                    arr.refresh = () => {};
                    arr[Symbol.iterator] = function*() { yield* plugins; };
                    return arr;
                }
            });
        JS;
    }

    private function buildWebGLOverrides(): string
    {
        $fp = $this->fingerprint;

        if ($fp->webglVendor === null && $fp->webglRenderer === null) {
            return '';
        }

        $vendor = json_encode($fp->webglVendor ?? 'Google Inc.', JSON_THROW_ON_ERROR);
        $renderer = json_encode($fp->webglRenderer ?? 'ANGLE (Intel, Intel(R) UHD Graphics 630, OpenGL 4.6)', JSON_THROW_ON_ERROR);

        return <<<JS
            const webglVendor = {$vendor};
            const webglRenderer = {$renderer};
            const overrideGetParameter = (proto) => {
                const orig = proto.getParameter;
                proto.getParameter = function(param) {
                    if (param === 37445) return webglVendor;
                    if (param === 37446) return webglRenderer;
                    return orig.call(this, param);
                };
            };
            if (typeof WebGLRenderingContext !== 'undefined') overrideGetParameter(WebGLRenderingContext.prototype);
            if (typeof WebGL2RenderingContext !== 'undefined') overrideGetParameter(WebGL2RenderingContext.prototype);
        JS;
    }

    private function buildCanvasNoiseInjection(): string
    {
        $fp = $this->fingerprint;

        $seed = crc32($fp->userAgent ?? 'default');
        $seedHex = dechex(abs($seed));

        return <<<JS
            const canvasSeed = 0x{$seedHex};
            const origToDataURL = HTMLCanvasElement.prototype.toDataURL;
            HTMLCanvasElement.prototype.toDataURL = function(type) {
                try {
                    const ctx = this.getContext('2d');
                    if (ctx && this.width > 0 && this.height > 0) {
                        const imageData = ctx.getImageData(0, 0, Math.min(this.width, 16), Math.min(this.height, 16));
                        const d = imageData.data;
                        for (let i = 0; i < d.length; i += 4) {
                            d[i] = d[i] ^ ((canvasSeed >> (i % 8)) & 1);
                        }
                        ctx.putImageData(imageData, 0, 0);
                    }
                } catch(e) {}
                return origToDataURL.apply(this, arguments);
            };
        JS;
    }

    private function buildScreenOverrides(): string
    {
        $fp = $this->fingerprint;

        if ($fp->screenWidth === null) {
            return '';
        }

        $w = $fp->screenWidth;
        $h = $fp->screenHeight ?? 1080;
        $aw = $fp->screenAvailWidth ?? $w;
        $ah = $fp->screenAvailHeight ?? ($h - 40);
        $cd = $fp->screenColorDepth ?? 24;

        return <<<JS
            Object.defineProperty(screen, 'width', {get: () => {$w}});
            Object.defineProperty(screen, 'height', {get: () => {$h}});
            Object.defineProperty(screen, 'availWidth', {get: () => {$aw}});
            Object.defineProperty(screen, 'availHeight', {get: () => {$ah}});
            Object.defineProperty(screen, 'colorDepth', {get: () => {$cd}});
            Object.defineProperty(screen, 'pixelDepth', {get: () => {$cd}});

            if (window.outerWidth === 0) {
                Object.defineProperty(window, 'outerWidth', {get: () => {$w}});
                Object.defineProperty(window, 'outerHeight', {get: () => {$h}});
            }
        JS;
    }

    private function buildConnectionOverrides(): string
    {
        $fp = $this->fingerprint;

        if ($fp->connectionType === null) {
            return '';
        }

        $type = json_encode($fp->connectionType, JSON_THROW_ON_ERROR);
        $rtt = $fp->connectionRtt ?? 50;
        $downlink = $fp->connectionDownlink ?? 10.0;

        return <<<JS
            if (navigator.connection) {
                Object.defineProperty(navigator.connection, 'effectiveType', {get: () => {$type}});
                Object.defineProperty(navigator.connection, 'rtt', {get: () => {$rtt}});
                Object.defineProperty(navigator.connection, 'downlink', {get: () => {$downlink}});
                Object.defineProperty(navigator.connection, 'saveData', {get: () => false});
            } else {
                Object.defineProperty(navigator, 'connection', {
                    get: () => ({effectiveType: {$type}, rtt: {$rtt}, downlink: {$downlink}, saveData: false})
                });
            }
        JS;
    }

    private function buildPermissionsOverrides(): string
    {
        return <<<'JS'
            const origPermQuery = window.navigator.permissions.query;
            window.navigator.permissions.query = (parameters) => (
                parameters.name === 'notifications'
                    ? Promise.resolve({state: Notification.permission})
                    : origPermQuery(parameters)
            );
        JS;
    }

    private function buildChromeObjectOverrides(): string
    {
        return <<<'JS'
            if (!window.chrome) {
                window.chrome = {};
            }
            window.chrome.runtime = window.chrome.runtime || {};
            window.chrome.loadTimes = window.chrome.loadTimes || function() { return {}; };
            window.chrome.csi = window.chrome.csi || function() { return {}; };
        JS;
    }
}
