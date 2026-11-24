<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\config;

use RuntimeException;

final class InvalidTelegramContentConfigException extends RuntimeException
{
    private function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    public static function invalidMode(): self
    {
        return new self('telegram_content_mode_invalid');
    }

    public static function catalogUnavailable(): self
    {
        return new self('telegram_catalog_unavailable');
    }
}
