<?php

declare(strict_types=1);

namespace modules\telegram\domain\valueObject;

use modules\telegram\domain\exception\TelegramSessionStateViolationException;

final class TelegramInteractionRevision
{
    /**
     * @throws TelegramSessionStateViolationException
     */
    public function __construct(private readonly int $value)
    {
        if ($value < 0) {
            throw new TelegramSessionStateViolationException('invalid_interaction_revision');
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * @throws TelegramSessionStateViolationException
     */
    public function next(): self
    {
        if ($this->value === PHP_INT_MAX) {
            throw new TelegramSessionStateViolationException('interaction_revision_overflow');
        }

        return new self($this->value + 1);
    }
}
