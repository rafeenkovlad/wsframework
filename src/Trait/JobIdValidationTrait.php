<?php

declare(strict_types=1);

namespace WsFramework\Trait;

use WsFramework\Dto\MethodDTO;

trait JobIdValidationTrait
{
    protected static function extractJobId(MethodDTO $methodDTO): ?string
    {
        $params = $methodDTO->params;
        $jobId = is_array($params) ? ($params['jobId'] ?? null) : null;

        if (!$jobId) {
            $methodDTO->response->errors = [['field' => 'jobId', 'message' => 'jobId is required']];
            return null;
        }

        return $jobId;
    }
}
