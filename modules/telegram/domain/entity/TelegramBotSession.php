<?php

declare(strict_types=1);

namespace modules\telegram\domain\entity;

use DateTimeImmutable;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\enum\TelegramSessionTransitionOutcome;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionDraft;
use modules\telegram\domain\valueObject\TelegramSessionTransitionResult;

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

    /** @throws TelegramSessionStateViolationException */
    public function beginOrder(string $orderSubjectId): TelegramSessionTransitionResult
    {
        if (($unsupported = $this->unsupportedResult()) !== null) {
            return $unsupported;
        }
        if ($this->flow !== TelegramSessionFlow::BROWSING) {
            return $this->result(TelegramSessionTransitionOutcome::INVALID_STATE);
        }

        $subject = self::normalizeReference($orderSubjectId);
        $revision = $this->interactionRevision->next();
        $result = new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::APPLIED,
            TelegramSessionFlow::AWAITING_LEAD_COMMENT,
            $revision,
        );

        $this->contentSubjectId = $subject;
        $this->flow = TelegramSessionFlow::AWAITING_LEAD_COMMENT;
        $this->interactionRevision = $revision;

        return $result;
    }

    /** @throws TelegramSessionStateViolationException */
    public function acceptComment(string $text, DateTimeImmutable $now): TelegramSessionTransitionResult
    {
        if (($unsupported = $this->unsupportedResult()) !== null) {
            return $unsupported;
        }
        if ($this->flow !== TelegramSessionFlow::AWAITING_LEAD_COMMENT) {
            return $this->result(TelegramSessionTransitionOutcome::INVALID_STATE);
        }

        $draft = TelegramSessionDraft::tryCreate($text, $now);
        if ($draft === null) {
            return $this->result(TelegramSessionTransitionOutcome::INVALID_COMMENT);
        }
        $revision = $this->interactionRevision->next();
        $result = new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::APPLIED,
            TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION,
            $revision,
        );

        $this->draft = $draft;
        $this->flow = TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION;
        $this->interactionRevision = $revision;

        return $result;
    }

    /** @throws TelegramSessionStateViolationException */
    public function confirmOrder(
        TelegramInteractionRevision $expectedRevision,
        DateTimeImmutable $now,
        ?string $browsingSubjectId,
    ): TelegramSessionTransitionResult {
        if (($unsupported = $this->unsupportedResult()) !== null) {
            return $unsupported;
        }
        if (!$this->interactionRevision->equals($expectedRevision)) {
            return $this->result(TelegramSessionTransitionOutcome::STALE_REVISION);
        }
        if ($this->flow !== TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION || $this->draft === null) {
            return $this->result(TelegramSessionTransitionOutcome::INVALID_STATE);
        }

        $expired = $this->draft->isExpiredAt($now);
        $subject = self::normalizeReference($browsingSubjectId);

        return $this->finishBrowsing(
            $subject,
            $expired ? TelegramSessionTransitionOutcome::DRAFT_EXPIRED : TelegramSessionTransitionOutcome::APPLIED,
        );
    }

    /** @throws TelegramSessionStateViolationException */
    public function cancelInline(
        TelegramInteractionRevision $expectedRevision,
        ?string $browsingSubjectId,
    ): TelegramSessionTransitionResult {
        if (($unsupported = $this->unsupportedResult()) !== null) {
            return $unsupported;
        }
        if (!$this->interactionRevision->equals($expectedRevision)) {
            return $this->result(TelegramSessionTransitionOutcome::STALE_REVISION);
        }

        return $this->cancelCurrentOrder($browsingSubjectId);
    }

    /** @throws TelegramSessionStateViolationException */
    public function cancelCurrentOrder(?string $browsingSubjectId): TelegramSessionTransitionResult
    {
        if (($unsupported = $this->unsupportedResult()) !== null) {
            return $unsupported;
        }
        if ($this->flow === TelegramSessionFlow::BROWSING) {
            return $this->result(TelegramSessionTransitionOutcome::NO_CHANGE);
        }

        return $this->finishBrowsing(self::normalizeReference($browsingSubjectId), TelegramSessionTransitionOutcome::APPLIED);
    }

    /** @throws TelegramSessionStateViolationException */
    public function restartBrowsing(?string $browsingSubjectId): TelegramSessionTransitionResult
    {
        if (($unsupported = $this->unsupportedResult()) !== null) {
            return $unsupported;
        }

        return $this->finishBrowsing(self::normalizeReference($browsingSubjectId), TelegramSessionTransitionOutcome::APPLIED);
    }

    /** @throws TelegramSessionStateViolationException */
    public function expireDraft(DateTimeImmutable $now, ?string $browsingSubjectId): TelegramSessionTransitionResult
    {
        if (($unsupported = $this->unsupportedResult()) !== null) {
            return $unsupported;
        }
        if ($this->draft === null) {
            return $this->result(TelegramSessionTransitionOutcome::NO_CHANGE);
        }
        if (!$this->draft->isExpiredAt($now)) {
            return $this->result(TelegramSessionTransitionOutcome::NO_CHANGE);
        }

        return $this->finishBrowsing(self::normalizeReference($browsingSubjectId), TelegramSessionTransitionOutcome::DRAFT_EXPIRED);
    }

    public function canBrowseNext(): bool
    {
        return $this->contentMode === TelegramContentMode::DEMO && $this->flow === TelegramSessionFlow::BROWSING;
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

    private function unsupportedResult(): ?TelegramSessionTransitionResult
    {
        if ($this->contentMode !== TelegramContentMode::DEMO) {
            return $this->result(TelegramSessionTransitionOutcome::UNSUPPORTED_MODE);
        }
        if ($this->flow === TelegramSessionFlow::LEAD_REPLY) {
            return $this->result(TelegramSessionTransitionOutcome::UNSUPPORTED_FLOW);
        }

        return null;
    }

    private function result(TelegramSessionTransitionOutcome $outcome): TelegramSessionTransitionResult
    {
        return new TelegramSessionTransitionResult($outcome, $this->flow, $this->interactionRevision);
    }

    private function finishBrowsing(?string $browsingSubjectId, TelegramSessionTransitionOutcome $outcome): TelegramSessionTransitionResult
    {
        $revision = $this->interactionRevision->next();
        $result = new TelegramSessionTransitionResult($outcome, TelegramSessionFlow::BROWSING, $revision);

        $this->flow = TelegramSessionFlow::BROWSING;
        $this->contentSubjectId = $browsingSubjectId;
        $this->draft = null;
        $this->interactionRevision = $revision;

        return $result;
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
