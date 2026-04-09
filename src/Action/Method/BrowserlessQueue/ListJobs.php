<?php

declare(strict_types=1);

namespace WsFramework\Action\Method\BrowserlessQueue;

use WsFramework\Action\Method\MethodAbstract;
use WsFramework\Action\Response\Ok;
use WsFramework\Channel\KVNatsBucket\KVNatsBucket;
use WsFramework\Dto\BrowserlessListJobsParamsDTO;
use WsFramework\Dto\MethodDTO;
use WsFramework\Dto\UseCase\JobKVDTO;
use WsFramework\Middleware\ApiKeyAuth;
use WsFramework\Middleware\MethodParamsToDTO;
use Symfony\Component\Validator\Validation;
use WsFramework\Pool\Http\PoolHttpConnection;

class ListJobs extends MethodAbstract
{
    public static function getMethodName(): string
    {
        return 'BrowserlessQueue.ListJobs';
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

    protected static function middleware(int $workerId, int $connectionId, MethodDTO $methodDTO): bool
    {
        if (!ApiKeyAuth::check($methodDTO)) {
            return false;
        }
        MethodParamsToDTO::main($methodDTO, BrowserlessListJobsParamsDTO::class);
        return true;
    }

    public static function validate(MethodDTO $methodDTO, &$errors): void
    {
        /** @var BrowserlessListJobsParamsDTO $params */
        $params = $methodDTO->params;

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $errors = $validator->validate($params);
    }

    protected static function process(int $workerId, int $connectionId, MethodDTO $methodDTO): array
    {
        /** @var BrowserlessListJobsParamsDTO $params */
        $params = $methodDTO->params;
        $kv = KVNatsBucket::bucketInterface()->bucket('browserless_jobs_status');

        $entries = $kv->getAll();
        $jobs = [];
        foreach ($entries as $entry) {
            $data = json_decode($entry->value, true);
            if (!is_array($data) || empty($data['jobId'])) {
                continue;
            }
            $job = JobKVDTO::createFromArray($data);
            if ($params->status !== null && ($job->status ?? '') !== $params->status) {
                continue;
            }
            $jobs[] = $job;
        }

        $total = count($jobs);

        usort($jobs, function (JobKVDTO $a, JobKVDTO $b) use ($params) {
            $cmp = ($a->createdAt ?? '') <=> ($b->createdAt ?? '');
            return $params->sortOrder === 'desc' ? -$cmp : $cmp;
        });

        $jobs = array_slice($jobs, $params->offset, $params->limit);

        return [
            'jobs' => array_map(fn(JobKVDTO $j) => $j->toArray(), $jobs),
            'total' => $total,
            'limit' => $params->limit,
            'offset' => $params->offset,
        ];
    }

    protected static function getDescription(): string
    {
        return 'Получить список задач browserless-очереди с фильтрацией по статусу и пагинацией.';
    }

    protected static function getSchemaArgsDescriptor(): array
    {
        return [
            OpenRpcSchema::descriptor(
                'status',
                OpenRpcSchema::statusSchema('Опциональный фильтр по статусу задачи.'),
                false,
                'Фильтр по статусу.',
            ),
            OpenRpcSchema::descriptor(
                'limit',
                [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Максимальное количество задач в ответе.',
                ],
                false,
                'Лимит элементов.',
            ),
            OpenRpcSchema::descriptor(
                'offset',
                [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Смещение для пагинации.',
                ],
                false,
                'Смещение.',
            ),
            OpenRpcSchema::descriptor(
                'sortOrder',
                [
                    'type' => 'string',
                    'enum' => ['asc', 'desc'],
                    'description' => 'Порядок сортировки по времени создания.',
                ],
                false,
                'Порядок сортировки.',
            ),
        ];
    }

    protected static function getResult(): ?array
    {
        return [
            'type' => 'object',
            'required' => ['jobs', 'total', 'limit', 'offset'],
            'additionalProperties' => false,
            'properties' => [
                'jobs' => [
                    'type' => 'array',
                    'items' => OpenRpcSchema::browserlessJobSchema(),
                    'description' => 'Список задач на текущей странице.',
                ],
                'total' => [
                    'type' => 'integer',
                    'description' => 'Общее количество задач после применения фильтра.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Текущий лимит пагинации.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Текущее смещение пагинации.',
                ],
            ],
        ];
    }
}
