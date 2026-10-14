<?php

declare(strict_types=1);

namespace tests\fixtures\telegram\session;

use DateTimeImmutable;
use modules\telegram\application\port\ITelegramSessionClock;

final class FrozenTelegramSessionClock implements ITelegramSessionClock
{
    public int $calls = 0;

    public function __construct(public DateTimeImmutable $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        ++$this->calls;

        return $this->instant;
    }
}
