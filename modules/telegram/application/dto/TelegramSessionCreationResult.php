<?php

declare(strict_types=1);

namespace modules\telegram\application\dto;

final class TelegramSessionCreationResult
{
    public function __construct(
        private readonly VersionedTelegramBotSession $versionedSession,
        private readonly bool $created,
    ) {
    }

    public function versionedSession(): VersionedTelegramBotSession
    {
        return $this->versionedSession;
    }

    public function created(): bool
    {
        return $this->created;
    }
}
