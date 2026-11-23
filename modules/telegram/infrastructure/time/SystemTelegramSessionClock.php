<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\time;

use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\application\port\ITelegramSessionClock;

final class SystemTelegramSessionClock implements ITelegramSessionClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
