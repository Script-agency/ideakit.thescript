<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use DateTimeImmutable;

interface ITelegramSessionClock
{
    /** Returns an instant in UTC. */
    public function now(): DateTimeImmutable;
}
