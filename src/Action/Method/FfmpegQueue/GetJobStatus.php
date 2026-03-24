<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\FfmpegQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\FfmpegNatsChannel\FfmpegNatsChannel;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Pool\Http\PoolHttpConnection;
use WsFramework\Trait\FfmpegJobIdValidationTrait;

class GetJobStatus extends MethodAbstract
{
    use FfmpegJobIdValidationTrait;

    public static function getMethodName(): string
    {
        return 'ffmpegQueue.getJobStatus';
    }

    public static function getResponseClass(): string
    {
        return Ok::class;
    }

    public static function getChannelClass(): ?string
    {
        return null;
    }

    public static function getPoolConnectionClass(): ?string
    {
        return PoolHttpConnection::class;
    }

    public static function isDisabledResponse(): bool
    {
        return false;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        $jobId = static::extractJobId($methodDTO);
        if ($jobId === null) {
            return [];
        }

        $value = FfmpegNatsChannel::eventInterface()->bucket('ffmpeg_jobs_status')->get($jobId);

        if (!$value) {
            $methodDTO->response->errors = [['field' => 'jobId', 'message' => 'Job not found']];
            return [];
        }

        $jobData = JobKVDTO::createFromArray(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
        return $jobData->toArray();
    }

    protected static function getDescription(): string
    {
        return 'Получить текущее состояние задачи FFmpeg по её идентификатору.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            OpenRpcSchema::jobIdDescriptor(),
        ];
    }

    protected static function getResult(): ?array
    {
        return OpenRpcSchema::ffmpegJobSchema();
    }
}
