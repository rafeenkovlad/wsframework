<?php

declare(strict_types=1);

namespace WsFramework\Service\Video;

use Package\NatsClient\NatsKeyValueInterface;
use WsFramework\Action\Method\FfmpegQueue\Dlq;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsDlqChannel;
use WsFramework\Channel\S3NatsChannel\S3NatsChannel;
use WsFramework\Dto\FfmpegJobDTO;
use WsFramework\Dto\S3UploadDataDTO;
use WsFramework\Enum\FfmpegJobStatus;
use WsFramework\Process\DefaultProcess\BackgroundProcessAbstract;

readonly class FfmpegJobExecutor
{
    public function __construct(
        private readonly FfmpegVideoConverter $converter,
        private readonly string $filesDirectory,
        private readonly NatsKeyValueInterface $kv,
    ) {}

    public function execute(FfmpegJobDTO $dto): void
    {
        if (!$dto->jobId) {
            throw new \RuntimeException('FfmpegJobExecutor: missing jobId');
        }

        $jobId = $dto->jobId;
        $kv = $this->kv;

        // Проверка статуса
        $existing = $kv->get($jobId);
        if ($existing) {
            $jobData = FfmpegJobDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR));

            if ($jobData->status === FfmpegJobStatus::CANCELLED->value) {
                echo "FfmpegJobExecutor: job {$jobId} cancelled, skipping\n";
                return;
            }

            if ($jobData->status === FfmpegJobStatus::S3_UPLOAD_PENDING->value) {
                $this->republishToS3Upload($jobId, $jobData);
                return;
            }

            $skipStatuses = [
                FfmpegJobStatus::S3_UPLOADING->value,
                FfmpegJobStatus::COMPLETED->value,
            ];
            if (in_array($jobData->status, $skipStatuses, true)) {
                echo "FfmpegJobExecutor: job {$jobId} already past processing ({$jobData->status}), skipping\n";
                return;
            }
        }

        // Обработка
        BackgroundProcessAbstract::kvMerge($kv, $jobId, [
            'status' => FfmpegJobStatus::PROCESSING->value,
            'startedAt' => date('c'),
            'error' => null,
            'errorMessage' => null,
        ]);

        try {
            $inputFile = $dto->inputFile;
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

            BackgroundProcessAbstract::kvMerge($kv, $jobId, [
                'status' => FfmpegJobStatus::S3_UPLOAD_PENDING->value,
                'outputFile' => $outputFile,
                'localHlsDir' => $localHlsDir,
                'playlistFile' => $playlistFile,
                'segmentCount' => $segmentCount,
            ]);

            $refreshed = $kv->get($jobId);
            $refreshedData = FfmpegJobDTO::createFromArray(json_decode($refreshed, true, 512, JSON_THROW_ON_ERROR));

            S3NatsChannel::eventInterface()->publish(
                S3UploadDataDTO::createFromArray([
                    'jobId' => $jobId,
                    'localHlsDir' => $localHlsDir,
                    'playlistFile' => $playlistFile,
                    'segmentCount' => $segmentCount,
                    's3Bucket' => $refreshedData->s3Bucket ?? $_ENV['S3_BUCKET'],
                    'outputS3Prefix' => $refreshedData->outputS3Prefix ?? '',
                ])->jsonEncode(),
                S3NatsChannel::METHOD_UPLOAD,
            );

            echo "FfmpegJobExecutor: job {$jobId} converted, published to s3_upload\n";
        } catch (\Throwable $e) {
            $this->handleError($kv, $jobId, $dto, $e);
        }
    }

    private function republishToS3Upload(string $jobId, FfmpegJobDTO $jobData): void
    {
        echo "FfmpegJobExecutor: job {$jobId} is s3_upload_pending, re-publishing to s3_upload\n";

        S3NatsChannel::eventInterface()->publish(
            S3UploadDataDTO::createFromArray([
                'jobId' => $jobId,
                'localHlsDir' => $jobData->localHlsDir,
                'playlistFile' => $jobData->playlistFile,
                'segmentCount' => $jobData->segmentCount,
                's3Bucket' => $jobData->s3Bucket,
                'outputS3Prefix' => $jobData->outputS3Prefix,
            ])->jsonEncode(),
            S3NatsChannel::METHOD_UPLOAD,
        );

        echo "FfmpegJobExecutor: job {$jobId} re-published to s3_upload\n";
    }

    private function handleError(
        NatsKeyValueInterface $kv,
        string $jobId,
        FfmpegJobDTO $dto,
        \Throwable $e,
    ): void {
        $existing = $kv->get($jobId);
        $jobData = $existing ? FfmpegJobDTO::createFromArray(json_decode($existing, true, 512, JSON_THROW_ON_ERROR) ?: []) : FfmpegJobDTO::createWithDefaultValues();
        $retryCount = $jobData->retryCount ?? 0;
        $maxRetries = $jobData->maxRetries;

        if ($retryCount < $maxRetries) {
            BackgroundProcessAbstract::kvMerge($kv, $jobId, [
                'status' => FfmpegJobStatus::FAILED->value,
                'retryCount' => $retryCount + 1,
                'error' => $e->getMessage(),
                'errorMessage' => $e->getMessage(),
            ]);

            try {
                FfmpegNatsDlqChannel::eventInterface()->publish(
                    json_encode([
                        'jobId' => $jobId,
                        'error' => $e->getMessage(),
                        'originalData' => $dto->toArray(),
                        'failedAt' => date('c'),
                    ]),
                    Dlq::getMethodName(),
                );
            } catch (\Throwable $dlqError) {
                echo "FfmpegJobExecutor: DLQ publish error: {$dlqError->getMessage()}\n";
            }

            echo "FfmpegJobExecutor: job {$jobId} retry {$retryCount}/{$maxRetries}, sent to DLQ\n";
        } else {
            BackgroundProcessAbstract::kvMerge($kv, $jobId, [
                'status' => FfmpegJobStatus::FAILED->value,
                'error' => $e->getMessage(),
                'errorMessage' => $e->getMessage(),
                'finishedAt' => date('c'),
            ]);

            echo "FfmpegJobExecutor: job {$jobId} failed permanently: {$e->getMessage()}\n";
        }
    }
}
