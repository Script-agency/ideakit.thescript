<?php

declare(strict_types=1);

namespace modules\telegram\domain\valueObject;

use modules\telegram\domain\exception\TelegramSessionStateViolationException;

final class TelegramBotSessionId
{
    private readonly string $value;

    /**
     * @throws TelegramSessionStateViolationException
     */
    public function __construct(string $value)
    {
        $normalized = strtolower($value);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $normalized) !== 1) {
            throw new TelegramSessionStateViolationException('invalid_telegram_bot_session_id');
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
