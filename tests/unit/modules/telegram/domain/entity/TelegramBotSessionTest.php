<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\domain\entity;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\enum\TelegramSessionTransitionOutcome;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionDraft;
use modules\telegram\domain\valueObject\TelegramSessionTransitionResult;

/** @phpstan-type SessionOperation 'begin'|'comment'|'confirm'|'inline'|'cancel'|'restart'|'expire' */
final class TelegramBotSessionTest extends Unit
{
    private const SESSION_ID = '01890f4d-3c2a-7f48-8c0b-123456789abc';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789abd';
    private const SUBJECT_ID = '01890f4d-3c2a-4f48-8c0b-123456789abe';
    private const LEAD_ID = '01890f4d-3c2a-4f48-8c0b-123456789abf';

    /**
     * @dataProvider browsingSubjects
     */
    public function testCreatesDemoWithExplicitBrowsingContext(?string $subject): void
    {
        $id = new TelegramBotSessionId(self::SESSION_ID);
        $botKey = str_repeat('я', 64);
        $session = TelegramBotSession::createDemo($id, strtoupper(self::PROFILE_ID), $botKey, PHP_INT_MAX, $subject);

        self::assertSame($id, $session->getId());
        self::assertSame(self::PROFILE_ID, $session->getTelegramIdentityProfileId());
        self::assertSame($botKey, $session->getBotKey());
        self::assertSame(PHP_INT_MAX, $session->getChatId());
        self::assertSame(TelegramContentMode::DEMO, $session->getContentMode());
        self::assertSame(TelegramSessionFlow::BROWSING, $session->getFlow());
        self::assertSame($subject === null ? null : strtolower($subject), $session->getContentSubjectId());
        self::assertNull($session->getAgencyLeadId());
        self::assertNull($session->getDraft());
        self::assertSame(0, $session->getInteractionRevision()->value());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function browsingSubjects(): iterable
    {
        yield 'unknown context' => [null];
        yield 'uuid v4 context' => [strtoupper(self::SUBJECT_ID)];
    }

    /**
     * @dataProvider storedStates
     */
    public function testRestoresStateWithoutTransitionsOrExpiryCleanup(
        TelegramContentMode $mode,
        TelegramSessionFlow $flow,
        bool $hasDraft,
    ): void {
        $id = new TelegramBotSessionId(self::SESSION_ID);
        $revision = new TelegramInteractionRevision(PHP_INT_MAX);
        $draft = $hasDraft ? self::expiredDraft() : null;
        $session = TelegramBotSession::restore(
            $id,
            self::PROFILE_ID,
            'тестовый бот',
            100001,
            $mode,
            $flow,
            strtoupper(self::SUBJECT_ID),
            strtoupper(self::LEAD_ID),
            $draft,
            $revision,
        );

        self::assertSame($id, $session->getId());
        self::assertSame(self::PROFILE_ID, $session->getTelegramIdentityProfileId());
        self::assertSame('тестовый бот', $session->getBotKey());
        self::assertSame(100001, $session->getChatId());
        self::assertSame($mode, $session->getContentMode());
        self::assertSame($flow, $session->getFlow());
        self::assertSame(self::SUBJECT_ID, $session->getContentSubjectId());
        self::assertSame(self::LEAD_ID, $session->getAgencyLeadId());
        self::assertSame($draft, $session->getDraft());
        self::assertSame($revision, $session->getInteractionRevision());
    }

    /**
     * @return iterable<string, array{TelegramContentMode, TelegramSessionFlow, bool}>
     */
    public static function storedStates(): iterable
    {
        yield 'browsing' => [TelegramContentMode::DEMO, TelegramSessionFlow::BROWSING, false];
        yield 'comment' => [TelegramContentMode::DEMO, TelegramSessionFlow::AWAITING_LEAD_COMMENT, false];
        yield 'confirmation' => [TelegramContentMode::DEMO, TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, true];
        yield 'lead reply' => [TelegramContentMode::DEMO, TelegramSessionFlow::LEAD_REPLY, false];
        yield 'catalog browsing' => [TelegramContentMode::CATALOG, TelegramSessionFlow::BROWSING, false];
        yield 'catalog confirmation' => [TelegramContentMode::CATALOG, TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, true];
    }

    public function testRestoreDoesNotInventReferencesForReservedFlow(): void
    {
        $session = TelegramBotSession::restore(
            new TelegramBotSessionId(self::SESSION_ID),
            self::PROFILE_ID,
            'test-bot',
            1,
            TelegramContentMode::CATALOG,
            TelegramSessionFlow::LEAD_REPLY,
            null,
            null,
            null,
            new TelegramInteractionRevision(0),
        );

        self::assertSame(TelegramContentMode::CATALOG, $session->getContentMode());
        self::assertSame(TelegramSessionFlow::LEAD_REPLY, $session->getFlow());
        self::assertNull($session->getContentSubjectId());
        self::assertNull($session->getAgencyLeadId());
        self::assertNull($session->getDraft());
    }

    /**
     * @dataProvider invalidCreationValues
     */
    public function testRejectsInvalidCreationValues(
        string $profileId,
        string $botKey,
        int $chatId,
        ?string $subject,
        string $reason,
    ): void {
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage($reason);

        TelegramBotSession::createDemo(new TelegramBotSessionId(self::SESSION_ID), $profileId, $botKey, $chatId, $subject);
    }

    /**
     * @return iterable<string, array{string, string, int, ?string, string}>
     */
    public static function invalidCreationValues(): iterable
    {
        yield 'malformed profile' => ['invalid', 'bot', 1, null, 'invalid_session_profile_id'];
        yield 'wrong profile version' => [self::SUBJECT_ID, 'bot', 1, null, 'invalid_session_profile_id'];
        yield 'padded profile' => [' ' . self::PROFILE_ID, 'bot', 1, null, 'invalid_session_profile_id'];
        yield 'empty bot' => [self::PROFILE_ID, '', 1, null, 'invalid_session_bot_key'];
        yield 'long bot' => [self::PROFILE_ID, str_repeat('я', 65), 1, null, 'invalid_session_bot_key'];
        yield 'invalid utf8 bot' => [self::PROFILE_ID, "\xC3\x28", 1, null, 'invalid_session_bot_key'];
        yield 'leading bot space' => [self::PROFILE_ID, ' bot', 1, null, 'invalid_session_bot_key'];
        yield 'trailing unicode space' => [self::PROFILE_ID, "bot\u{00A0}", 1, null, 'invalid_session_bot_key'];
        yield 'zero chat' => [self::PROFILE_ID, 'bot', 0, null, 'invalid_session_chat_id'];
        yield 'negative chat' => [self::PROFILE_ID, 'bot', -1, null, 'invalid_session_chat_id'];
        yield 'empty subject' => [self::PROFILE_ID, 'bot', 1, '', 'invalid_session_reference'];
        yield 'malformed subject' => [self::PROFILE_ID, 'bot', 1, 'invalid', 'invalid_session_reference'];
        yield 'padded subject' => [self::PROFILE_ID, 'bot', 1, self::SUBJECT_ID . "\n", 'invalid_session_reference'];
    }

    /**
     * @dataProvider invalidStoredReferences
     */
    public function testRestoreValidatesStoredReferences(string $profileId, ?string $subject, ?string $lead, string $reason): void
    {
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage($reason);

        TelegramBotSession::restore(
            new TelegramBotSessionId(self::SESSION_ID),
            $profileId,
            'bot',
            1,
            TelegramContentMode::DEMO,
            TelegramSessionFlow::BROWSING,
            $subject,
            $lead,
            null,
            new TelegramInteractionRevision(0),
        );
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, string}>
     */
    public static function invalidStoredReferences(): iterable
    {
        yield 'profile' => ['invalid', null, null, 'invalid_session_profile_id'];
        yield 'subject' => [self::PROFILE_ID, 'invalid', null, 'invalid_session_reference'];
        yield 'lead' => [self::PROFILE_ID, null, 'invalid', 'invalid_session_reference'];
    }

    /**
     * @dataProvider invalidDraftStates
     */
    public function testRestoreRejectsContradictoryDraftState(TelegramSessionFlow $flow, bool $hasDraft): void
    {
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage('invalid_session_draft_state');

        TelegramBotSession::restore(
            new TelegramBotSessionId(self::SESSION_ID),
            self::PROFILE_ID,
            'bot',
            1,
            TelegramContentMode::DEMO,
            $flow,
            self::SUBJECT_ID,
            null,
            $hasDraft ? self::expiredDraft() : null,
            new TelegramInteractionRevision(4),
        );
    }

    /**
     * @return iterable<string, array{TelegramSessionFlow, bool}>
     */
    public static function invalidDraftStates(): iterable
    {
        yield 'missing confirmation draft' => [TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, false];
        yield 'browsing with draft' => [TelegramSessionFlow::BROWSING, true];
        yield 'comment with draft' => [TelegramSessionFlow::AWAITING_LEAD_COMMENT, true];
        yield 'lead reply with draft' => [TelegramSessionFlow::LEAD_REPLY, true];
    }

    public function testOrderLifecycleUsesExplicitBrowsingContextAndImmutableResults(): void
    {
        $session = TelegramBotSession::createDemo(
            new TelegramBotSessionId(self::SESSION_ID),
            self::PROFILE_ID,
            'bot',
            1,
            self::SUBJECT_ID,
        );
        $begin = $session->beginOrder(strtoupper(self::LEAD_ID));
        self::assertTransition($session, $begin, TelegramSessionTransitionOutcome::APPLIED, TelegramSessionFlow::AWAITING_LEAD_COMMENT, 1);
        self::assertSame(self::LEAD_ID, $session->getContentSubjectId());
        self::assertNull($session->getDraft());

        $accepted = $session->acceptComment("Нужен\r\nMVP\t", self::time('2026-10-12 10:00:00.123456'));
        self::assertTransition($session, $accepted, TelegramSessionTransitionOutcome::APPLIED, TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, 2);
        $draft = $session->getDraft();
        self::assertNotNull($draft);
        self::assertSame("Нужен\nMVP", $draft->comment());
        self::assertSame('2026-10-13 10:00:00.123456', $draft->expiresAt()->format('Y-m-d H:i:s.u'));
        $before = self::snapshot($session);
        $staleCancel = $session->cancelInline($begin->interactionRevision, self::SUBJECT_ID);
        self::assertSame(TelegramSessionTransitionOutcome::STALE_REVISION, $staleCancel->outcome);
        self::assertSame($before, self::snapshot($session));

        $confirmed = $session->confirmOrder($accepted->interactionRevision, self::time('2026-10-13 10:00:00.123455'), self::SUBJECT_ID);
        self::assertTransition($session, $confirmed, TelegramSessionTransitionOutcome::APPLIED, TelegramSessionFlow::BROWSING, 3);
        self::assertSame(self::SUBJECT_ID, $session->getContentSubjectId());
        self::assertNull($session->getDraft());
        self::assertNull($session->getAgencyLeadId());
        self::assertSame(TelegramSessionFlow::AWAITING_LEAD_COMMENT, $begin->flow);
        self::assertSame(1, $begin->interactionRevision->value());
        self::assertSame(TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, $accepted->flow);
        self::assertSame(2, $accepted->interactionRevision->value());

        $before = self::snapshot($session);
        $repeated = $session->confirmOrder($accepted->interactionRevision, self::time(), null);
        self::assertTransition($session, $repeated, TelegramSessionTransitionOutcome::STALE_REVISION, TelegramSessionFlow::BROWSING, 3);
        self::assertSame($before, self::snapshot($session));
    }

    /**
     * @dataProvider invalidOperationStates
     * @param SessionOperation $operation
     */
    public function testWrongStageDoesNotChangeState(string $operation, TelegramSessionFlow $flow): void
    {
        $session = self::sessionAt($flow);
        $before = self::snapshot($session);
        $result = self::operate($session, $operation);
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::INVALID_STATE, $flow, 4);
        self::assertSame($before, self::snapshot($session));
    }

    /** @return iterable<string, array{SessionOperation, TelegramSessionFlow}> */
    public static function invalidOperationStates(): iterable
    {
        yield 'order during comment' => ['begin', TelegramSessionFlow::AWAITING_LEAD_COMMENT];
        yield 'order during confirmation' => ['begin', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION];
        yield 'text during browsing' => ['comment', TelegramSessionFlow::BROWSING];
        yield 'text during confirmation' => ['comment', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION];
        yield 'confirm during browsing' => ['confirm', TelegramSessionFlow::BROWSING];
        yield 'confirm during comment' => ['confirm', TelegramSessionFlow::AWAITING_LEAD_COMMENT];
    }

    public function testInvalidCommentDoesNotConsumeRevisionEvenAtMaximum(): void
    {
        $session = self::sessionAt(TelegramSessionFlow::AWAITING_LEAD_COMMENT, PHP_INT_MAX);
        $before = self::snapshot($session);
        $result = $session->acceptComment("\u{00A0}\n", self::time());
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::INVALID_COMMENT, TelegramSessionFlow::AWAITING_LEAD_COMMENT, PHP_INT_MAX);
        self::assertSame($before, self::snapshot($session));
    }

    /** @dataProvider staleActions */
    public function testStaleActionPrecedesStateTimeAndSubjectValidation(string $operation, TelegramSessionFlow $flow): void
    {
        $session = self::sessionAt($flow);
        $before = self::snapshot($session);
        $stale = new TelegramInteractionRevision(3);
        $result = $operation === 'confirm'
            ? $session->confirmOrder($stale, new DateTimeImmutable('2026-10-12', new DateTimeZone('Europe/Berlin')), 'invalid')
            : $session->cancelInline($stale, 'invalid');
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::STALE_REVISION, $flow, 4);
        self::assertSame($before, self::snapshot($session));
    }

    /** @return iterable<string, array{string, TelegramSessionFlow}> */
    public static function staleActions(): iterable
    {
        yield 'expired confirmation' => ['confirm', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION];
        yield 'expired cancellation' => ['inline', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION];
        yield 'wrong confirmation stage' => ['confirm', TelegramSessionFlow::AWAITING_LEAD_COMMENT];
        yield 'inactive cancellation' => ['inline', TelegramSessionFlow::BROWSING];
    }

    /** @dataProvider expiryBoundaries */
    public function testConfirmationExpiryBoundary(string $now, TelegramSessionTransitionOutcome $outcome): void
    {
        $session = self::sessionAt(TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION);
        $result = $session->confirmOrder(new TelegramInteractionRevision(4), self::time($now), null);
        self::assertTransition($session, $result, $outcome, TelegramSessionFlow::BROWSING, 5);
        self::assertNull($session->getDraft());
        self::assertNull($session->getContentSubjectId());
        self::assertSame(self::LEAD_ID, $session->getAgencyLeadId());
    }

    /** @return iterable<string, array{string, TelegramSessionTransitionOutcome}> */
    public static function expiryBoundaries(): iterable
    {
        yield 'before' => ['2000-01-01 10:00:00.123455', TelegramSessionTransitionOutcome::APPLIED];
        yield 'equal' => ['2000-01-01 10:00:00.123456', TelegramSessionTransitionOutcome::DRAFT_EXPIRED];
        yield 'after' => ['2000-01-01 10:00:00.123457', TelegramSessionTransitionOutcome::DRAFT_EXPIRED];
    }

    public function testExpirationIsAnExplicitIdempotentTransition(): void
    {
        $session = self::sessionAt(TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION);
        $before = self::snapshot($session);
        $result = $session->expireDraft(self::time('2000-01-01 10:00:00.123455'), self::LEAD_ID);
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::NO_CHANGE, TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, 4);
        self::assertSame($before, self::snapshot($session));
        $result = $session->expireDraft(self::time('2000-01-01 10:00:00.123456'), self::LEAD_ID);
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::DRAFT_EXPIRED, TelegramSessionFlow::BROWSING, 5);
        self::assertSame(self::LEAD_ID, $session->getContentSubjectId());
        self::assertNull($session->getDraft());
        $before = self::snapshot($session);
        $result = $session->expireDraft(self::time(), null);
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::NO_CHANGE, TelegramSessionFlow::BROWSING, 5);
        self::assertSame($before, self::snapshot($session));
    }

    /**
     * @dataProvider cancellations
     * @param SessionOperation $operation
     */
    public function testCancellationClearsOrderAndRepeatedCancellationDoesNothing(string $operation, TelegramSessionFlow $flow, ?string $subject): void
    {
        $session = self::sessionAt($flow);
        $result = self::operate($session, $operation, $subject);
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::APPLIED, TelegramSessionFlow::BROWSING, 5);
        self::assertSame($subject, $session->getContentSubjectId());
        self::assertNull($session->getDraft());
        self::assertSame(self::LEAD_ID, $session->getAgencyLeadId());
        $before = self::snapshot($session);
        $result = self::operate($session, $operation, 'invalid');
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::NO_CHANGE, TelegramSessionFlow::BROWSING, 5);
        self::assertSame($before, self::snapshot($session));
    }

    /** @return iterable<string, array{SessionOperation, TelegramSessionFlow, ?string}> */
    public static function cancellations(): iterable
    {
        yield 'inline comment' => ['inline', TelegramSessionFlow::AWAITING_LEAD_COMMENT, self::LEAD_ID];
        yield 'inline expired draft' => ['inline', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, null];
        yield 'current comment' => ['cancel', TelegramSessionFlow::AWAITING_LEAD_COMMENT, null];
        yield 'current expired draft' => ['cancel', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, self::LEAD_ID];
    }

    /** @dataProvider demoFlows */
    public function testRestartInvalidatesButtonsWithOneRevisionIncrement(TelegramSessionFlow $flow): void
    {
        $session = self::sessionAt($flow);
        $revision = $session->getInteractionRevision();
        $result = $session->restartBrowsing(null);
        self::assertTransition($session, $result, TelegramSessionTransitionOutcome::APPLIED, TelegramSessionFlow::BROWSING, 5);
        self::assertNull($session->getDraft());
        self::assertNull($session->getContentSubjectId());
        self::assertSame(self::LEAD_ID, $session->getAgencyLeadId());
        $before = self::snapshot($session);
        $stale = $session->cancelInline($revision, self::SUBJECT_ID);
        self::assertSame(TelegramSessionTransitionOutcome::STALE_REVISION, $stale->outcome);
        self::assertSame($before, self::snapshot($session));
    }

    /** @return iterable<string, array{TelegramSessionFlow}> */
    public static function demoFlows(): iterable
    {
        yield 'browsing' => [TelegramSessionFlow::BROWSING];
        yield 'comment' => [TelegramSessionFlow::AWAITING_LEAD_COMMENT];
        yield 'expired confirmation' => [TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION];
    }

    /** @dataProvider browsingStates */
    public function testNavigationIsReadOnly(TelegramContentMode $mode, TelegramSessionFlow $flow, bool $allowed): void
    {
        $session = self::sessionAt($flow, 4, $mode);
        $before = self::snapshot($session);
        self::assertSame($allowed, $session->canBrowseNext());
        self::assertSame($before, self::snapshot($session));
    }

    /** @return iterable<string, array{TelegramContentMode, TelegramSessionFlow, bool}> */
    public static function browsingStates(): iterable
    {
        yield 'demo browsing' => [TelegramContentMode::DEMO, TelegramSessionFlow::BROWSING, true];
        yield 'comment' => [TelegramContentMode::DEMO, TelegramSessionFlow::AWAITING_LEAD_COMMENT, false];
        yield 'confirmation' => [TelegramContentMode::DEMO, TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, false];
        yield 'reply' => [TelegramContentMode::DEMO, TelegramSessionFlow::LEAD_REPLY, false];
        yield 'catalog' => [TelegramContentMode::CATALOG, TelegramSessionFlow::BROWSING, false];
    }

    /** @dataProvider unsupportedStates */
    public function testUnsupportedStatePreservesEveryField(TelegramContentMode $mode, TelegramSessionFlow $flow, TelegramSessionTransitionOutcome $outcome): void
    {
        $session = self::sessionAt($flow, PHP_INT_MAX, $mode);
        $before = self::snapshot($session);
        foreach (['begin', 'comment', 'confirm', 'inline', 'cancel', 'restart', 'expire'] as $operation) {
            $result = self::operate($session, $operation);
            self::assertTransition($session, $result, $outcome, $flow, PHP_INT_MAX);
            self::assertSame($before, self::snapshot($session));
        }
    }

    /** @return iterable<string, array{TelegramContentMode, TelegramSessionFlow, TelegramSessionTransitionOutcome}> */
    public static function unsupportedStates(): iterable
    {
        yield 'catalog draft' => [TelegramContentMode::CATALOG, TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, TelegramSessionTransitionOutcome::UNSUPPORTED_MODE];
        yield 'reply' => [TelegramContentMode::DEMO, TelegramSessionFlow::LEAD_REPLY, TelegramSessionTransitionOutcome::UNSUPPORTED_FLOW];
        yield 'mode precedes flow' => [TelegramContentMode::CATALOG, TelegramSessionFlow::LEAD_REPLY, TelegramSessionTransitionOutcome::UNSUPPORTED_MODE];
    }

    /**
     * @dataProvider overflowOperations
     * @param SessionOperation $operation
     */
    public function testOverflowPreventsPartialMutation(string $operation, TelegramSessionFlow $flow): void
    {
        $session = self::sessionAt($flow, PHP_INT_MAX);
        $before = self::snapshot($session);
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage('interaction_revision_overflow');
        try {
            self::operate($session, $operation);
        } finally {
            self::assertSame($before, self::snapshot($session));
        }
    }

    /** @return iterable<string, array{SessionOperation, TelegramSessionFlow}> */
    public static function overflowOperations(): iterable
    {
        yield 'begin' => ['begin', TelegramSessionFlow::BROWSING];
        yield 'comment' => ['comment', TelegramSessionFlow::AWAITING_LEAD_COMMENT];
        yield 'expired cleanup' => ['confirm', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION];
    }

    /**
     * @dataProvider invalidTransitionArguments
     * @param SessionOperation $operation
     */
    public function testInvalidArgumentsDoNotPartiallyMutate(string $operation, TelegramSessionFlow $flow, bool $invalidTime): void
    {
        $session = self::sessionAt($flow);
        $before = self::snapshot($session);
        $now = $invalidTime ? new DateTimeImmutable('2026-10-12', new DateTimeZone('Europe/Berlin')) : self::time();
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage($invalidTime ? 'session_time_must_be_utc' : 'invalid_session_reference');
        try {
            self::operate($session, $operation, $invalidTime ? null : 'invalid', $now);
        } finally {
            self::assertSame($before, self::snapshot($session));
        }
    }

    /** @return iterable<string, array{SessionOperation, TelegramSessionFlow, bool}> */
    public static function invalidTransitionArguments(): iterable
    {
        yield 'order subject' => ['begin', TelegramSessionFlow::BROWSING, false];
        yield 'confirmation subject' => ['confirm', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, false];
        yield 'inline subject' => ['inline', TelegramSessionFlow::AWAITING_LEAD_COMMENT, false];
        yield 'cancel subject' => ['cancel', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, false];
        yield 'restart subject' => ['restart', TelegramSessionFlow::BROWSING, false];
        yield 'expiry subject' => ['expire', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, false];
        yield 'comment time' => ['comment', TelegramSessionFlow::AWAITING_LEAD_COMMENT, true];
        yield 'confirmation time' => ['confirm', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, true];
        yield 'expiry time' => ['expire', TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, true];
    }

    private static function sessionAt(TelegramSessionFlow $flow, int $revision = 4, TelegramContentMode $mode = TelegramContentMode::DEMO): TelegramBotSession
    {
        return TelegramBotSession::restore(
            new TelegramBotSessionId(self::SESSION_ID),
            self::PROFILE_ID,
            'bot',
            1,
            $mode,
            $flow,
            self::SUBJECT_ID,
            self::LEAD_ID,
            $flow === TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION ? self::expiredDraft() : null,
            new TelegramInteractionRevision($revision),
        );
    }

    /** @param SessionOperation $operation */
    private static function operate(TelegramBotSession $session, string $operation, ?string $subject = self::LEAD_ID, ?DateTimeImmutable $now = null): TelegramSessionTransitionResult
    {
        $now ??= self::time();
        return match ($operation) {
            'begin' => $session->beginOrder($subject ?? self::LEAD_ID),
            'comment' => $session->acceptComment('Тестовый комментарий', $now),
            'confirm' => $session->confirmOrder($session->getInteractionRevision(), $now, $subject),
            'inline' => $session->cancelInline($session->getInteractionRevision(), $subject),
            'cancel' => $session->cancelCurrentOrder($subject),
            'restart' => $session->restartBrowsing($subject),
            'expire' => $session->expireDraft($now, $subject),
        };
    }

    private static function assertTransition(TelegramBotSession $session, TelegramSessionTransitionResult $result, TelegramSessionTransitionOutcome $outcome, TelegramSessionFlow $flow, int $revision): void
    {
        self::assertSame($outcome, $result->outcome);
        self::assertSame($flow, $result->flow);
        self::assertSame($flow, $session->getFlow());
        self::assertSame($revision, $result->interactionRevision->value());
        self::assertSame($result->interactionRevision, $session->getInteractionRevision());
        self::assertSame(in_array($outcome, [TelegramSessionTransitionOutcome::APPLIED, TelegramSessionTransitionOutcome::DRAFT_EXPIRED], true), $result->requiresSave());
    }

    /** @return array{TelegramBotSessionId, string, string, int, TelegramContentMode, TelegramSessionFlow, ?string, ?string, ?TelegramSessionDraft, TelegramInteractionRevision} */
    private static function snapshot(TelegramBotSession $session): array
    {
        return [
            $session->getId(), $session->getTelegramIdentityProfileId(), $session->getBotKey(), $session->getChatId(),
            $session->getContentMode(), $session->getFlow(), $session->getContentSubjectId(), $session->getAgencyLeadId(),
            $session->getDraft(), $session->getInteractionRevision(),
        ];
    }

    private static function time(string $value = '2026-10-12 10:00:00.123456'): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function expiredDraft(): TelegramSessionDraft
    {
        return TelegramSessionDraft::restore(
            'Тестовый комментарий',
            new DateTimeImmutable('2000-01-01 10:00:00.123456', new DateTimeZone('UTC')),
        );
    }
}
