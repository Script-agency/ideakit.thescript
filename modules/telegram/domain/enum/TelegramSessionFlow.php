<?php

declare(strict_types=1);

namespace modules\telegram\domain\enum;

enum TelegramSessionFlow: string
{
    case BROWSING = 'BROWSING';
    case AWAITING_LEAD_COMMENT = 'AWAITING_LEAD_COMMENT';
    case AWAITING_LEAD_CONFIRMATION = 'AWAITING_LEAD_CONFIRMATION';
    case LEAD_REPLY = 'LEAD_REPLY';
}
