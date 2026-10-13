<?php

declare(strict_types=1);

namespace modules\telegram\domain\enum;

enum TelegramSessionTransitionOutcome: string
{
    case APPLIED = 'APPLIED';
    case NO_CHANGE = 'NO_CHANGE';
    case INVALID_STATE = 'INVALID_STATE';
    case STALE_REVISION = 'STALE_REVISION';
    case INVALID_COMMENT = 'INVALID_COMMENT';
    case DRAFT_EXPIRED = 'DRAFT_EXPIRED';
    case UNSUPPORTED_FLOW = 'UNSUPPORTED_FLOW';
    case UNSUPPORTED_MODE = 'UNSUPPORTED_MODE';
}
