<?php

declare(strict_types=1);

namespace modules\telegram\presentation\enum;

enum TelegramMessageRenderingErrorCode: string
{
    case INVALID_TEXT = 'invalid_text';
    case TEXT_TOO_LONG = 'text_too_long';
}
