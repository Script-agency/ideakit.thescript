<?php

declare(strict_types=1);

namespace modules\telegram\domain\enum;

enum TelegramContentMode: string
{
    case DEMO = 'DEMO';
    case CATALOG = 'CATALOG';
}
