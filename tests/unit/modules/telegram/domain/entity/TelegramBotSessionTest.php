<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\domain\entity;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionDraft;

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

    private static function expiredDraft(): TelegramSessionDraft
    {
        return TelegramSessionDraft::restore(
            'Тестовый комментарий',
            new DateTimeImmutable('2000-01-01 10:00:00.123456', new DateTimeZone('UTC')),
        );
    }
}
