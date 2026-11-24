<?php

declare(strict_types=1);

namespace modules\telegram\presentation\exception;

use modules\telegram\presentation\enum\TelegramMessageRenderingErrorCode;
use RuntimeException;

final class TelegramMessageRenderingException extends RuntimeException
{
    public function __construct(private readonly TelegramMessageRenderingErrorCode $reason)
    {
        parent::__construct($reason->value);
    }

    public function reason(): TelegramMessageRenderingErrorCode
    {
        return $this->reason;
    }
}
