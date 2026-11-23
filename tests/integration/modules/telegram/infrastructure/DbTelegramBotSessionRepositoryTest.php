<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use DateInterval;
use DateTimeImmutable;
use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode as ErrorCode;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\infrastructure\db\DbTelegramBotSessionRepository;
use modules\telegram\infrastructure\mapper\TelegramBotSessionMapper;
use tests\fixtures\telegram\session\FrozenTelegramSessionClock;
use tests\fixtures\telegram\session\TelegramSessionScenario;
use Throwable;
use yii\db\Connection;

final class DbTelegramBotSessionRepositoryTest extends Unit
{
    private const SUBJECT = '550e8400-e29b-41d4-a716-446655440000';
    private const OTHER_PROFILE = '01890f4d-3c2a-7f48-8c0b-123456789ab2';

    private Connection $db;
    private TelegramSessionScenario $scenario;
    private FrozenTelegramSessionClock $clock;
    private DbTelegramBotSessionRepository $repository;

    protected function _before(): void
    {
        $this->db = TelegramSessionScenario::connection();
        try {
            TelegramSessionScenario::begin($this->db);
            $this->scenario = TelegramSessionScenario::seed($this->db);
            $this->clock = TelegramSessionScenario::clock();
            $this->repository = TelegramSessionScenario::repository($this->db, $this->clock);
        } catch (Throwable $failure) {
            try {
                $this->release();
            } finally {
                throw $failure;
            }
        }
    }

    protected function _after(): void
    {
        $this->release();
    }

    public function testCreatesAndKeepsExistingSessionWithoutReset(): void
    {
        $candidate = $this->scenario->candidate();
        $first = $this->repository->createIfAbsent($candidate);
        self::assertTrue($first->created());
        self::assertEquals($candidate, $first->versionedSession()->session());
        self::assertSame(0, $first->versionedSession()->lockVersion());
        self::assertSame(1, $this->clock->calls);
        $initial = $this->scenario->snapshot($this->db);
        $this->assertInstant($this->clock->instant, $initial['created_at']);
        self::assertSame($initial['created_at'], $initial['updated_at']);
        $session = $first->versionedSession()->session();
        $session->beginOrder(self::SUBJECT);
        $session->acceptComment('Synthetic private draft', $this->clock->instant);
        $this->repository->save($session, 0);
        $before = $this->scenario->snapshot($this->db);
        $this->clock->instant = $this->clock->instant->add(new DateInterval('PT1H'));
        $second = $this->repository->createIfAbsent($this->scenario->candidate());
        self::assertFalse($second->created());
        self::assertEquals($session, $second->versionedSession()->session());
        self::assertSame(1, $second->versionedSession()->lockVersion());
        self::assertSame($before, $this->scenario->snapshot($this->db));
    }

    public function testWritesOneVersionAndRejectsStaleNoChange(): void
    {
        $created = $this->repository->createIfAbsent($this->scenario->candidate())->versionedSession();
        $initial = $this->scenario->snapshot($this->db);
        $session = $created->session();
        $session->beginOrder(self::SUBJECT);
        $session->acceptComment('Synthetic private draft', $this->clock->instant);
        $this->clock->instant = $this->clock->instant->add(new DateInterval('PT1H'));
        $saved = $this->repository->save($session, 0);
        self::assertEquals($session, $saved->session());
        self::assertSame(1, $saved->lockVersion());
        self::assertSame(2, $saved->session()->getInteractionRevision()->value());
        self::assertSame(2, $this->clock->calls);
        $before = $this->scenario->snapshot($this->db);
        foreach (['id', 'telegram_identity_profile_id', 'bot_key', 'chat_id', 'content_mode', 'agency_lead_id', 'created_at'] as $column) {
            self::assertSame($initial[$column], $before[$column]);
        }
        $this->assertInstant($this->clock->instant, $before['updated_at']);
        self::assertNotSame($initial['tuple_location'], $before['tuple_location']);
        $calls = $this->clock->calls;
        self::assertSame(1, $this->repository->save($saved->session(), 1)->lockVersion());
        self::assertSame($calls, $this->clock->calls);
        // ctid also detects an UPDATE that writes identical values.
        self::assertSame($before, $this->scenario->snapshot($this->db));
        $this->assertFailure(ErrorCode::VERSION_CONFLICT, fn () => $this->repository->save($saved->session(), 0));
        self::assertSame($before, $this->scenario->snapshot($this->db));
    }

    public function testMaximumVersionAllowsNoChangeButRejectsChange(): void
    {
        $created = $this->repository->createIfAbsent($this->scenario->candidate())->versionedSession();
        $this->db->createCommand()->update(
            '{{%telegram_bot_sessions}}',
            ['lock_version' => PHP_INT_MAX],
            ['id' => $created->session()->getId()->value()],
        )->execute();
        $loaded = $this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, $this->scenario->profileId);
        self::assertNotNull($loaded);
        $before = $this->scenario->snapshot($this->db);
        $calls = $this->clock->calls;
        self::assertSame(PHP_INT_MAX, $this->repository->save($loaded->session(), PHP_INT_MAX)->lockVersion());
        self::assertSame($before, $this->scenario->snapshot($this->db));
        $loaded->session()->restartBrowsing(null);
        $this->assertFailure(ErrorCode::VERSION_OVERFLOW, fn () => $this->repository->save($loaded->session(), PHP_INT_MAX));
        self::assertSame($calls, $this->clock->calls);
        self::assertSame($before, $this->scenario->snapshot($this->db));
    }

    /**
     * @dataProvider changedImmutableFields
     * @param array<string, mixed> $changes
     */
    public function testRejectsRestoredImmutableChanges(array $changes, ErrorCode $code): void
    {
        $this->repository->createIfAbsent($this->scenario->candidate());
        $before = $this->scenario->snapshot($this->db);
        $candidate = (new TelegramBotSessionMapper())->fromRow(array_replace($before, $changes))->session();
        $this->assertFailure($code, fn () => $this->repository->save($candidate, 0));
        self::assertSame($before, $this->scenario->snapshot($this->db));
    }

    /** @return iterable<string, array{array<string, mixed>, ErrorCode}> */
    public static function changedImmutableFields(): iterable
    {
        yield 'owner' => [['telegram_identity_profile_id' => self::OTHER_PROFILE], ErrorCode::OWNER_MISMATCH];
        yield 'bot' => [['bot_key' => 'session-another'], ErrorCode::INVALID_ARGUMENT];
        yield 'chat' => [['chat_id' => 92], ErrorCode::INVALID_ARGUMENT];
        yield 'mode' => [['content_mode' => 'CATALOG'], ErrorCode::MODE_MISMATCH];
        yield 'lead' => [['agency_lead_id' => self::SUBJECT], ErrorCode::INVALID_ARGUMENT];
    }

    public function testMissingLoadAndSaveHaveDifferentOutcomes(): void
    {
        self::assertNull($this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, $this->scenario->profileId));
        $this->assertFailure(ErrorCode::NOT_FOUND, fn () => $this->repository->save($this->scenario->candidate(), 0));
        self::assertSame(0, $this->clock->calls);
    }

    /**
     * @dataProvider invalidInitialStates
     * @param array<string, mixed> $changes
     */
    public function testRejectsNonInitialCandidate(array $changes): void
    {
        $mapper = new TelegramBotSessionMapper();
        $row = $mapper->toInsertParameters($this->scenario->candidate(), $this->clock->instant);
        $candidate = $mapper->fromRow(array_replace($row, $changes))->session();
        $this->assertFailure(ErrorCode::INVALID_ARGUMENT, fn () => $this->repository->createIfAbsent($candidate));
        self::assertSame(0, $this->clock->calls);
        self::assertNull($this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, $this->scenario->profileId));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInitialStates(): iterable
    {
        yield 'revision' => [['interaction_revision' => 1]];
        yield 'flow' => [['flow_code' => 'AWAITING_LEAD_COMMENT']];
        yield 'mode' => [['content_mode' => 'CATALOG']];
        yield 'lead' => [['agency_lead_id' => self::SUBJECT]];
        yield 'confirmation' => [['flow_code' => 'AWAITING_LEAD_CONFIRMATION', 'draft_comment' => 'Synthetic',
            'expires_at' => '2026-10-14T10:00:00+00:00']];
    }

    /** @dataProvider invalidLoadArguments */
    public function testRejectsInvalidArguments(string $bot, int $chat, string $profile): void
    {
        $this->assertFailure(ErrorCode::INVALID_ARGUMENT, fn () => $this->repository->loadForUpdate($bot, $chat, $profile));
        $this->assertFailure(ErrorCode::INVALID_ARGUMENT, fn () => $this->repository->save($this->scenario->candidate(), -1));
        self::assertSame(0, $this->clock->calls);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidLoadArguments(): iterable
    {
        yield 'bot' => [' bot', 91, self::OTHER_PROFILE];
        yield 'chat' => ['bot', 0, self::OTHER_PROFILE];
        yield 'profile' => ['bot', 91, self::SUBJECT];
    }

    public function testRejectsOtherOwnerOnLoadAndRepeatCreate(): void
    {
        $this->repository->createIfAbsent($this->scenario->candidate());
        $before = $this->scenario->snapshot($this->db);
        $this->assertFailure(
            ErrorCode::OWNER_MISMATCH,
            fn () => $this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, self::OTHER_PROFILE),
        );
        $mapper = new TelegramBotSessionMapper();
        $row = $mapper->toInsertParameters($this->scenario->candidate(), $this->clock->instant);
        $candidate = $mapper->fromRow(array_replace($row, ['telegram_identity_profile_id' => self::OTHER_PROFILE]))->session();
        $this->assertFailure(ErrorCode::OWNER_MISMATCH, fn () => $this->repository->createIfAbsent($candidate));
        self::assertSame($before, $this->scenario->snapshot($this->db));
        self::assertNotNull($this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, strtoupper($this->scenario->profileId)));
    }

    public function testLoadsReservedModeButRejectsDemoReplacement(): void
    {
        $candidate = $this->scenario->candidate();
        $row = (new TelegramBotSessionMapper())->toInsertParameters($candidate, $this->clock->instant);
        $row['content_mode'] = 'CATALOG';
        $row['flow_code'] = 'LEAD_REPLY';
        $row['agency_lead_id'] = self::SUBJECT;
        $this->db->createCommand()->insert('{{%telegram_bot_sessions}}', $row)->execute();
        $loaded = $this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, $this->scenario->profileId);
        self::assertNotNull($loaded);
        self::assertSame('CATALOG', $loaded->session()->getContentMode()->value);
        self::assertSame('LEAD_REPLY', $loaded->session()->getFlow()->value);
        $before = $this->scenario->snapshot($this->db);
        self::assertSame(0, $this->repository->save($loaded->session(), 0)->lockVersion());
        self::assertSame(0, $this->clock->calls);
        $this->assertFailure(ErrorCode::MODE_MISMATCH, fn () => $this->repository->createIfAbsent($candidate));
        self::assertSame($before, $this->scenario->snapshot($this->db));
    }

    public function testDoesNotNormalizeCorruptedDraftOrDecreaseRevision(): void
    {
        $session = $this->repository->createIfAbsent($this->scenario->candidate())->versionedSession()->session();
        $session->beginOrder(self::SUBJECT);
        $session->acceptComment('Synthetic draft', $this->clock->instant);
        $this->repository->save($session, 0);
        $before = $this->scenario->snapshot($this->db);
        $older = (new TelegramBotSessionMapper())->fromRow(array_replace($before, ['interaction_revision' => 1]))->session();
        $this->assertFailure(ErrorCode::INVALID_ARGUMENT, fn () => $this->repository->save($older, 1));
        self::assertSame($before, $this->scenario->snapshot($this->db));
        $this->db->createCommand()->update(
            '{{%telegram_bot_sessions}}',
            ['draft_comment' => "Synthetic\r\nnoncanonical"],
            ['id' => $session->getId()->value()],
        )->execute();
        $corrupt = $this->scenario->snapshot($this->db);
        $this->assertFailure(
            ErrorCode::INVALID_RECORD,
            fn () => $this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, $this->scenario->profileId),
        );
        self::assertSame($corrupt, $this->scenario->snapshot($this->db));
    }

    public function testExplicitExpiryClearsDraftWithoutTreatingReadAsCleanup(): void
    {
        $session = $this->repository->createIfAbsent($this->scenario->candidate())->versionedSession()->session();
        $session->beginOrder(self::SUBJECT);
        $session->acceptComment('Synthetic private draft', $this->clock->instant);
        $this->repository->save($session, 0);
        $this->clock->instant = $this->clock->instant->add(new DateInterval('PT24H'));
        $before = $this->scenario->snapshot($this->db);
        $calls = $this->clock->calls;
        $loaded = $this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, $this->scenario->profileId);
        self::assertNotNull($loaded);
        self::assertNotNull($loaded->session()->getDraft());
        self::assertSame($calls, $this->clock->calls);
        self::assertSame($before, $this->scenario->snapshot($this->db));
        self::assertSame('DRAFT_EXPIRED', $loaded->session()->expireDraft($this->clock->instant, null)->outcome->value);
        $saved = $this->repository->save($loaded->session(), $loaded->lockVersion());
        self::assertNull($saved->session()->getDraft());
        self::assertSame(3, $saved->session()->getInteractionRevision()->value());
        self::assertSame(2, $saved->lockVersion());
        $after = $this->scenario->snapshot($this->db);
        self::assertNull($after['draft_comment']);
        self::assertNull($after['expires_at']);
        self::assertSame($before['created_at'], $after['created_at']);
    }

    public function testDoesNotCommitCreationOnBehalfOfCaller(): void
    {
        $this->repository->createIfAbsent($this->scenario->candidate());
        $transaction = $this->db->getTransaction();
        self::assertNotNull($transaction);
        self::assertSame(1, $transaction->getLevel());
        $transaction->rollBack();
        self::assertFalse($this->db->createCommand(
            'SELECT id FROM {{%telegram_bot_sessions}} WHERE bot_key = :bot',
            [':bot' => $this->scenario->botKey],
        )->queryScalar());
    }

    public function testCallerRollbackRestoresPreviouslyCommittedSession(): void
    {
        $observer = TelegramSessionScenario::connection();
        try {
            $session = $this->repository->createIfAbsent($this->scenario->candidate())->versionedSession()->session();
            $transaction = $this->db->getTransaction();
            self::assertNotNull($transaction);
            $transaction->commit();
            $before = $this->scenario->snapshot($observer);
            $transaction = TelegramSessionScenario::begin($this->db);
            $session->beginOrder(self::SUBJECT);
            $session->acceptComment('Synthetic rollback draft', $this->clock->instant);
            $this->clock->instant = $this->clock->instant->add(new DateInterval('PT1H'));
            $saved = $this->repository->save($session, 0);
            self::assertSame(1, $saved->lockVersion());
            self::assertNotSame($before, $this->scenario->snapshot($this->db));
            self::assertSame($before, $this->scenario->snapshot($observer));
            $transaction->rollBack();
            self::assertSame($before, $this->scenario->snapshot($observer));
        } finally {
            try {
                $this->release();
                $this->scenario->cleanup($observer);
            } finally {
                $observer->close();
            }
        }
    }

    private function assertInstant(DateTimeImmutable $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        self::assertSame($expected->format('U.u'), (new DateTimeImmutable($actual))->format('U.u'));
    }

    private function assertFailure(ErrorCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected typed session storage refusal.');
        } catch (TelegramSessionPersistenceException $failure) {
            self::assertSame($code, $failure->errorCode);
            self::assertSame($code->value, $failure->getMessage());
        }
    }

    private function release(): void
    {
        if (!isset($this->db)) {
            return;
        }
        try {
            $this->db->getTransaction()?->rollBack();
        } finally {
            $this->db->close();
        }
    }
}
