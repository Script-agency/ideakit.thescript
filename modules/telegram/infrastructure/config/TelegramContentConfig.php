<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\config;

use modules\telegram\domain\enum\TelegramContentMode;

final class TelegramContentConfig
{
    private function __construct(public readonly TelegramContentMode $mode)
    {
    }

    /**
     * @param array<string, mixed> $environment
     * @throws InvalidTelegramContentConfigException
     */
    public static function fromEnvironment(array $environment): self
    {
        $mode = match ($environment['TELEGRAM_CONTENT_MODE'] ?? null) {
            'demo' => TelegramContentMode::DEMO,
            'catalog' => TelegramContentMode::CATALOG,
            default => throw InvalidTelegramContentConfigException::invalidMode(),
        };

        return new self($mode);
    }
}
