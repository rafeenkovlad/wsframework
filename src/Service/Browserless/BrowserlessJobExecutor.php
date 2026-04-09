<?php

declare(strict_types=1);

namespace WsFramework\Service\Browserless;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use JsonException;
use WsFramework\Dto\Pool\BrowserFingerprintDTO;
use WsFramework\Dto\UseCase\BrowserlessJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Dto\UseCase\KVMergeOptionsDTO;
use WsFramework\Enum\BrowserlessJobStatus;
use WsFramework\Enum\ClaimResult;
use WsFramework\Enum\JobType;
use WsFramework\Enum\Pipeline;
use WsFramework\Exception\S3\PipelineException;
use WsFramework\Exception\UseCaseException;
use WsFramework\Pool\Browserless\PoolBrowserFingerprint;
use WsFramework\UseCase\ClaimJobStageUseCase;
use WsFramework\UseCase\DefineCurrentPipelineUseCase;
use WsFramework\UseCase\DispatchJobByStatusUseCase;
use WsFramework\UseCase\JobKVMergeUseCase;
use WsFramework\UseCase\ThrowableHandleUseCase;

class BrowserlessJobExecutor
{
    private Client $httpClient;

    public function __construct(
        private readonly string $apiUrl,
    ) {
        $this->httpClient = new Client([
            'base_uri'    => $this->apiUrl,
            'timeout'     => $_ENV['BROWSERLESS_CLIENT_TIMEOUT'] ,
            'http_errors' => false,
        ]);
    }

    /**
     * @throws PipelineException
     */
    private function saveOutputFile(string $path, string $body, string $jobId): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new PipelineException(
                Pipeline::BROWSERLESS->getName(),
                "Failed to create output directory for job {$jobId}: {$dir}",
            );
        }

        if (file_put_contents($path, $body) === false) {
            throw new PipelineException(
                Pipeline::BROWSERLESS->getName(),
                "Failed to write output for job {$jobId}: {$path}",
            );
        }
    }

    /**
     * @throws JsonException
     * @throws PipelineException
     * @throws UseCaseException
     */
    private function checkClaimedStart(string $jobId): void
    {
        if (!$jobId) {
            throw new PipelineException(DefineCurrentPipelineUseCase::handle()->getName(), 'missing jobId');
        }

        $result = ClaimJobStageUseCase::handle(
            new JobKVDTO(jobId: $jobId),
            allowedStatuses: [
                BrowserlessJobStatus::BROWSERLESS_PENDING->value,
                BrowserlessJobStatus::BROWSERLESS_PROCESSING_RESTARTED->value,
            ],
            activeStatus: BrowserlessJobStatus::BROWSERLESS_PROCESSING->value,
            jobType: JobType::BROWSERLESS,
        );

        if ($result !== ClaimResult::CLAIMED) {
            $ex = new PipelineException(DefineCurrentPipelineUseCase::handle()->getName(), "job {$jobId} skip — {$result->name}\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    /**
     * @throws JsonException
     * @throws PipelineException
     */
    private function checkFailed(string $jobId): void
    {
        $kv = JobType::BROWSERLESS->kvBucket();
        $existing = $kv->get($jobId);

        if ($existing === null) {
            throw new PipelineException('BrowserlessQueueProcess', "job {$jobId} not found in KV\n");
        }

        $jobKVDTO = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

        if (BrowserlessJobStatus::BROWSERLESS_FAILED->value === $jobKVDTO->status) {
            $ex = new PipelineException('BrowserlessQueueProcess', "job {$jobId} skip — FAILED\n");
            echo $ex->getMessage();
            throw $ex;
        }
    }

    private function buildPuppeteerCode(
        string $format,
        BrowserFingerprintDTO $fingerprint,
        int $viewportWidth,
        int $viewportHeight,
        bool $stealthEnabled,
        ?array $cookies = null,
    ): string {
        $builder = new AntiDetectionScriptBuilder($fingerprint, $viewportWidth, $viewportHeight, $stealthEnabled);
        $antiDetectionJs = $builder->build();

        $jsUserAgent = json_encode($fingerprint->userAgent);
        $jsAcceptLang = json_encode($fingerprint->acceptLanguage);
        $jsAntiDetection = json_encode($antiDetectionJs);

        $outputCode = match ($format) {
            'pdf'   => "await page.addStyleTag({ content: '* { overflow-wrap: break-word; word-break: break-word; }' });\n      const result = await page.pdf({ format: 'A4', printBackground: true });\n      const resultType = 'application/pdf';",
            default => "const result = await page.screenshot({ fullPage: true, type: 'png' });\n      const resultType = 'image/png';",
        };

        $cookieInjection = '';
        if ($cookies !== null && $cookies !== []) {
            $jsCookies = json_encode($cookies, JSON_THROW_ON_ERROR);
            $cookieInjection = <<<JS

              // Cookie injection from Chrome profile
              const savedCookies = {$jsCookies};
              if (savedCookies.length > 0) {
                  await page.setCookie(...savedCookies);
              }
            JS;
        }

        return <<<JS
        module.exports = async ({ page, context }) => {
          await page.setUserAgent({$jsUserAgent});
          await page.setViewport({ width: {$viewportWidth}, height: {$viewportHeight} });
          await page.setExtraHTTPHeaders({ 'Accept-Language': {$jsAcceptLang} });
          await page.evaluateOnNewDocument({$jsAntiDetection});

          if (context.proxyUsername) {
              await page.authenticate({
                  username: context.proxyUsername,
                  password: context.proxyPassword || ''
              });
          }
          {$cookieInjection}

          await page.goto(context.url, { waitUntil: 'networkidle2', timeout: 180000 });

          {$outputCode}

          return { data: result, type: resultType };
        };
        JS;
    }

    /**
     * @throws JsonException
     * @throws PipelineException
     * @throws UseCaseException
     * @throws \Throwable
     */
    public function execute(string $jobId): JobKVDTO
    {
        $kv = JobType::BROWSERLESS->kvBucket();
        $this->checkFailed($jobId);
        $this->checkClaimedStart($jobId);

        $existing = $kv->get($jobId);
        $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
        $browserlessJob = $jobData->browserlessJob;

        $cookieStorage = new CookieStorageProvider();
        $tmpProfilePath = null;

        try {
            if ($browserlessJob === null) {
                throw new PipelineException(Pipeline::BROWSERLESS->getName(), "browserlessJob is null for job {$jobId}");
            }

            $url = $browserlessJob->url;
            if (!is_string($url) || trim($url) === '') {
                throw new PipelineException(Pipeline::BROWSERLESS->getName(), "url is required for job {$jobId}");
            }

            $format = $browserlessJob->format ?? 'screenshot';
            $viewportWidth = $browserlessJob->viewportWidth ?? 1920;
            $viewportHeight = $browserlessJob->viewportHeight ?? 1080;
            $proxy = $browserlessJob->proxy ?? $_ENV['BROWSERLESS_PROXY_URL'] ?? null;
            $proxyUsername = $browserlessJob->proxyUsername ?? $_ENV['BROWSERLESS_PROXY_USERNAME'] ?? null;
            $proxyPassword = $browserlessJob->proxyPassword ?? $_ENV['BROWSERLESS_PROXY_PASSWORD'] ?? null;

            $fingerprintName = $browserlessJob->fingerprint;
            if ($fingerprintName !== null && !PoolBrowserFingerprint::exists($fingerprintName)) {
                throw new PipelineException(
                    Pipeline::BROWSERLESS->getName(),
                    "Unknown fingerprint: {$fingerprintName}",
                );
            }

            if ($fingerprintName === null) {
                $fingerprintName = PoolBrowserFingerprint::getRandomName();
            }
            $fingerprint = PoolBrowserFingerprint::getOffset($fingerprintName);

            if ($fingerprint->viewportWidth !== null && $fingerprint->viewportHeight !== null) {
                $viewportWidth = $fingerprint->viewportWidth;
                $viewportHeight = $fingerprint->viewportHeight;
            }

            $launchArgs = [
                '--disable-blink-features=AutomationControlled',
                '--disable-features=site-per-process',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--disable-default-apps',
                '--disable-component-update',
                '--disable-background-networking',
            ];
            if ($proxy !== null && $proxy !== '') {
                $launchArgs[] = '--proxy-server=' . $proxy;
            }

            // Copy master profile to tmp if it exists
            if ($cookieStorage->profileExists($fingerprintName)) {
                try {
                    $tmpProfilePath = $cookieStorage->copyProfileToTmp($fingerprintName, $jobId);
                    $launchArgs[] = '--user-data-dir=' . $tmpProfilePath;
                    echo "BrowserlessJobExecutor: job {$jobId} — using profile copy for fingerprint {$fingerprintName}\n";
                } catch (\Throwable $e) {
                    echo "BrowserlessJobExecutor: job {$jobId} — profile copy failed: {$e->getMessage()}\n";
                    $tmpProfilePath = null;
                }
            }

            // Load saved cookies for injection
            $savedCookies = $cookieStorage->getCookies($fingerprintName);
            if ($savedCookies !== null) {
                echo "BrowserlessJobExecutor: job {$jobId} — injecting " . count($savedCookies) . " cookies for fingerprint {$fingerprintName}\n";
            }

            $stealthEnabled = filter_var($_ENV['BROWSERLESS_STEALTH_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

            $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'] ?? '/var/www/html/public/files/';

            $context = ['url' => $url];
            if ($proxyUsername !== null && $proxyUsername !== '') {
                $context['proxyUsername'] = $proxyUsername;
                $context['proxyPassword'] = $proxyPassword ?? '';
            }

            $requestBody = [
                'code'    => $this->buildPuppeteerCode(
                    $format,
                    $fingerprint,
                    $viewportWidth,
                    $viewportHeight,
                    $stealthEnabled,
                    $savedCookies,
                ),
                'context' => $context,
            ];

            $queryParts = [];
            foreach ($launchArgs as $arg) {
                if (str_contains($arg, '=')) {
                    [$key, $value] = explode('=', $arg, 2);
                    $queryParts[] = urlencode($key) . '=' . urlencode($value);
                } else {
                    $queryParts[] = urlencode($arg);
                }
            }
            $stealthParam = $stealthEnabled ? 'stealth&' : '';
            $endpoint = '/function?' . $stealthParam . '&' . implode('&', $queryParts);

            $response = $this->httpClient->post($endpoint, [
                RequestOptions::JSON => $requestBody,
            ]);

            $httpCode = $response->getStatusCode();
            if ($httpCode !== 200) {
                $errorBody = mb_substr((string) $response->getBody(), 0, 500);
                throw new PipelineException(
                    Pipeline::BROWSERLESS->getName(),
                    "Browserless API error for job {$jobId}: HTTP {$httpCode} — {$errorBody}",
                );
            }

            $body = (string) $response->getBody();

            $filename = $format === 'pdf' ? 'listing.pdf' : 'listing.png';
            $outputPath = "tmp_jobs/{$jobId}/{$filename}";

            $tmpFullPath = $filesDirectory . $outputPath;
            $this->saveOutputFile($tmpFullPath, $body, $jobId);

            // Cleanup tmp profile
            if ($tmpProfilePath !== null) {
                $cookieStorage->cleanupTmpProfile($jobId);
            }

            $jobData = new JobKVDTO(
                jobId: $jobId,
                status: BrowserlessJobStatus::BROWSERLESS_S3_UPLOAD_PENDING->value,
                browserlessJob: new BrowserlessJobDTO(
                    url: $url,
                    format: $format,
                    viewportWidth: $viewportWidth,
                    viewportHeight: $viewportHeight,
                    outputPath: $outputPath,
                    proxy: $proxy,
                    proxyUsername: $proxyUsername,
                    proxyPassword: $proxyPassword,
                    fingerprint: $fingerprintName,
                ),
            );

            JobKVMergeUseCase::handle(
                $jobData,
                KVMergeOptionsDTO::createFromArray([
                    'jobType' => JobType::BROWSERLESS,
                ]),
            );
            DispatchJobByStatusUseCase::handle($jobData);

            echo "BrowserlessJobExecutor: job {$jobId} → S3_UPLOAD_PENDING, fingerprint: {$fingerprintName}, output: {$outputPath}\n";

            return $jobData;
        } catch (\Throwable $e) {
            // Cleanup tmp profile on failure
            if ($tmpProfilePath !== null) {
                $cookieStorage->cleanupTmpProfile($jobId);
            }

            echo "BrowserlessJobExecutor: job {$jobId} retry {$jobData->retryCount}/{$jobData->maxRetries}\n";
            ThrowableHandleUseCase::handle($jobData, $e);

            return $jobData;
        }
    }
}
