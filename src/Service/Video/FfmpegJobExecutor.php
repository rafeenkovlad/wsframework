<?php

declare(strict_types=1);

namespace WsFramework\Service\Video;

use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Dto\UseCase\FfmpegJobDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;
use WsFramework\UseCase\ClaimJobStageUseCase;

readonly class FfmpegJobExecutor
{
    public function __construct(
        private FfmpegVideoConverter $converter,
        private string $filesDirectory,
        private NatsKeyValueInterface $kv,
    ) {}

    public function execute(string $jobId): void
    {
        if (!$jobId) {
            throw new \RuntimeException('FfmpegJobExecutor: missing jobId');
        }

        $kv = $this->kv;

        $claimed = ClaimJobStageUseCase::handle(
            new JobKVDTO(jobId: $jobId),
            $kv,
            allowedStatuses: [
                FfmpegJobStatus::PENDING->value,
                FfmpegJobStatus::PROCESSING_RESTARTED->value,
            ],
            activeStatus: FfmpegJobStatus::PROCESSING->value,
        );

        if (!$claimed) {
            echo "FfmpegJobExecutor: job {$jobId} not claimable, skipping\n";
            return;
        }

        $existing = $kv->get($jobId);
        $jobData = JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));
        $inputFile = $jobData->s3Download?->inputFile;

        try {
            if (!is_string($inputFile) || trim($inputFile) === '') {
                throw new \RuntimeException("inputFile is required for job {$jobId}");
            }

            $dimensions = $this->converter->getDimensions($inputFile);
            $outputFile = $this->converter->convertToHls(
                $inputFile,
                $dimensions['width'],
                $dimensions['height'],
            );

            $localHlsDir = $this->filesDirectory . dirname($outputFile) . '/';
            $playlistFile = basename($outputFile);
            $tsFiles = glob($localHlsDir . '*.ts');
            $segmentCount = count($tsFiles ?: []);

            BackgroundProcessAbstract::kvMerge($kv, new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::S3_UPLOAD_PENDING->value,
                ffmpegJob: new FfmpegJobDTO(
                    outputFile: $outputFile,
                    localHlsDir: $localHlsDir,
                    playlistFile: $playlistFile,
                    segmentCount: $segmentCount,
                ),
            ));

            echo "FfmpegJobExecutor: job {$jobId} converted, ready for s3_upload\n";
        } catch (\Throwable $e) {
            $this->handleError($kv, $jobId, $e);
        }
    }

    private function handleError(
        NatsKeyValueInterface $kv,
        string $jobId,
        \Throwable $e,
    ): void {
        $existing = $kv->get($jobId);
        $jobData = $existing
            ? JobKVDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR) ?: [])
            : JobKVDTO::createFromArray(['jobId' => $jobId]);
        $retryCount = $jobData->retryCount ?? 0;
        $maxRetries = $jobData->maxRetries ?? 3;

        if ($retryCount < $maxRetries) {
            BackgroundProcessAbstract::kvMerge($kv, new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::PENDING->value,
                retryCount: $retryCount + 1,
                ffmpegJob: new FfmpegJobDTO(
                    errors: [['message' => $e->getMessage(), 'at' => date('c')]],
                ),
            ));

            echo "FfmpegJobExecutor: job {$jobId} retry {$retryCount}/{$maxRetries}\n";
        } else {
            BackgroundProcessAbstract::kvMerge($kv, new JobKVDTO(
                jobId: $jobId,
                status: FfmpegJobStatus::FAILED->value,
                finishedAt: date('c'),
                ffmpegJob: new FfmpegJobDTO(
                    errors: [['message' => $e->getMessage(), 'at' => date('c')]],
                ),
            ));

            echo "FfmpegJobExecutor: job {$jobId} failed permanently: {$e->getMessage()}\n";
        }
    }
}
