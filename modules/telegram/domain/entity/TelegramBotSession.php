<?php

declare(strict_types=1);

namespace modules\telegram\domain\entity;

use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionDraft;

final class TelegramBotSession
{
    private readonly string $telegramIdentityProfileId;
    private ?string $contentSubjectId;
    private ?string $agencyLeadId;

    private function __construct(
        private readonly TelegramBotSessionId $id,
        string $profileId,
        private readonly string $botKey,
        private readonly int $chatId,
        private readonly TelegramContentMode $contentMode,
        private TelegramSessionFlow $flow,
        ?string $contentSubjectId,
        ?string $agencyLeadId,
        private ?TelegramSessionDraft $draft,
        private TelegramInteractionRevision $interactionRevision,
    ) {
        $profileId = strtolower($profileId);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $profileId) !== 1) {
            throw new TelegramSessionStateViolationException('invalid_session_profile_id');
        }
        if (trim($botKey) !== $botKey || preg_match('/\A\S(?:.{0,62}\S)?\z/us', $botKey) !== 1) {
            throw new TelegramSessionStateViolationException('invalid_session_bot_key');
        }
        if ($chatId <= 0) {
            throw new TelegramSessionStateViolationException('invalid_session_chat_id');
        }
        if (($flow === TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION) !== ($draft !== null)) {
            throw new TelegramSessionStateViolationException('invalid_session_draft_state');
        }

        $this->telegramIdentityProfileId = $profileId;
        $this->contentSubjectId = self::normalizeReference($contentSubjectId);
        $this->agencyLeadId = self::normalizeReference($agencyLeadId);
    }

    /**
     * @throws TelegramSessionStateViolationException
     */
    public static function createDemo(
        TelegramBotSessionId $id,
        string $profileId,
        string $botKey,
        int $chatId,
        ?string $contentSubjectId,
    ): self {
        return new self(
            $id,
            $profileId,
            $botKey,
            $chatId,
            TelegramContentMode::DEMO,
            TelegramSessionFlow::BROWSING,
            $contentSubjectId,
            null,
            null,
            new TelegramInteractionRevision(0),
        );
    }

    /**
     * @throws TelegramSessionStateViolationException
     */
    public static function restore(
        TelegramBotSessionId $id,
        string $profileId,
        string $botKey,
        int $chatId,
        TelegramContentMode $mode,
        TelegramSessionFlow $flow,
        ?string $contentSubjectId,
        ?string $agencyLeadId,
        ?TelegramSessionDraft $draft,
        TelegramInteractionRevision $revision,
    ): self {
        return new self(
            $id,
            $profileId,
            $botKey,
            $chatId,
            $mode,
            $flow,
            $contentSubjectId,
            $agencyLeadId,
            $draft,
            $revision,
        );
    }

    public function getId(): TelegramBotSessionId
    {
        return $this->id;
    }

    public function getTelegramIdentityProfileId(): string
    {
        return $this->telegramIdentityProfileId;
    }

    public function getBotKey(): string
    {
        return $this->botKey;
    }

    public function getChatId(): int
    {
        return $this->chatId;
    }

    public function getContentMode(): TelegramContentMode
    {
        return $this->contentMode;
    }

    public function getFlow(): TelegramSessionFlow
    {
        return $this->flow;
    }

    public function getContentSubjectId(): ?string
    {
        return $this->contentSubjectId;
    }

    public function getAgencyLeadId(): ?string
    {
        return $this->agencyLeadId;
    }

    public function getDraft(): ?TelegramSessionDraft
    {
        return $this->draft;
    }

    public function getInteractionRevision(): TelegramInteractionRevision
    {
        return $this->interactionRevision;
    }

    private static function normalizeReference(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $normalized = strtolower($id);
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $normalized) !== 1) {
            throw new TelegramSessionStateViolationException('invalid_session_reference');
        }

        return $normalized;
    }
}
