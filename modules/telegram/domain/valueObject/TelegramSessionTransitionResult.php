<?php

declare(strict_types=1);

namespace modules\telegram\domain\valueObject;

use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\enum\TelegramSessionTransitionOutcome;

final class TelegramSessionTransitionResult
{
    public function __construct(
        public readonly TelegramSessionTransitionOutcome $outcome,
        public readonly TelegramSessionFlow $flow,
        public readonly TelegramInteractionRevision $interactionRevision,
    ) {
    }

    public function requiresSave(): bool
    {
        return $this->outcome === TelegramSessionTransitionOutcome::APPLIED
            || $this->outcome === TelegramSessionTransitionOutcome::DRAFT_EXPIRED;
    }

    public function equals(self $other): bool
    {
        return $this->outcome === $other->outcome
            && $this->flow === $other->flow
            && $this->interactionRevision->equals($other->interactionRevision);
    }
}
