<?php

declare(strict_types=1);

namespace WsFramework\Enum;

enum FfmpegJobStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
