<?php

declare(strict_types=1);

namespace WsFramework\Enum;

enum ClaimResult
{
    case CLAIMED;
    case STATUS_MISMATCH;
    case CONFLICT;
}
