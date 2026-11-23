<?php

declare(strict_types=1);

namespace modules\telegram\application\dto;

use InvalidArgumentException;
use modules\telegram\domain\entity\TelegramBotSession;

final class VersionedTelegramBotSession
{
    public function __construct(
        private readonly TelegramBotSession $session,
        private readonly int $lockVersion,
    ) {
        if ($lockVersion < 0) {
            throw new InvalidArgumentException('negative_telegram_session_lock_version');
        }
    }

    public function session(): TelegramBotSession
    {
        return $this->session;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }
}
