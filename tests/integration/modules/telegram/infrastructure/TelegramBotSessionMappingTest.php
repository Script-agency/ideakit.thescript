<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\enum\TelegramSessionTransitionOutcome;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionDraft;
use modules\telegram\infrastructure\mapper\TelegramBotSessionMapper;
use Throwable;
use Yii;
use yii\db\Connection;
use yii\db\Transaction;

final class TelegramBotSessionMappingTest extends Unit
{
    private const USER_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab0';
    private const IDENTITY_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab1';
    private const PROFILE_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab2';
    private const SESSION_ID = '01890f4d-3c2a-7f48-8c0b-123456789ab3';
    private const SUBJECT_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const LEAD_ID = '550e8400-e29b-41d4-a716-446655440001';
    private const COMMENT = "  Synthetic запуск\nSecond line  ";

    private Connection $db;
    private ?Transaction $transaction = null;

    protected function _before(): void
    {
        $db = Yii::$app->get('db');
        self::assertInstanceOf(Connection::class, $db);
        self::assertSame('pgsql', $db->driverName);
        self::assertSame('ideakit_test', $db->createCommand('SELECT current_database()')->queryScalar());
        self::assertNull($db->getTransaction());
        $this->db = $db;
        $this->transaction = $db->beginTransaction();
        try {
            $db->createCommand("SET LOCAL DateStyle = 'ISO, YMD'")->execute();
            $db->createCommand("SET LOCAL TIME ZONE 'UTC'")->execute();
            $this->seedProfile();
        } catch (Throwable $failure) {
            try {
                $this->rollBack();
            } finally {
                throw $failure;
            }
        }
    }

    protected function _after(): void
    {
        $this->rollBack();
    }

    public function testRoundTripsConfirmationAndTechnicalVersion(): void
    {
        $now = new DateTimeImmutable('2026-10-13T10:00:00.123456', new DateTimeZone('UTC'));
        $session = TelegramBotSession::createDemo(
            new TelegramBotSessionId(self::SESSION_ID),
            self::PROFILE_ID,
            'session-mapping-test',
            91,
            null,
        );
        $session->beginOrder(self::SUBJECT_ID);
        $session->acceptComment(self::COMMENT, $now);
        $mapper = new TelegramBotSessionMapper();
        self::assertSame(1, $this->db->createCommand()->insert(
            '{{%telegram_bot_sessions}}',
            $mapper->toInsertParameters($session, $now),
        )->execute());
        self::assertSame(1, $this->db->createCommand()->update(
            '{{%telegram_bot_sessions}}',
            ['lock_version' => 7],
            ['id' => self::SESSION_ID],
        )->execute());
        $this->db->createCommand("SET LOCAL TIME ZONE 'Europe/Moscow'")->execute();
        $row = $this->loadRow();
        self::assertIsString($row['expires_at']);
        self::assertStringEndsWith('+03', $row['expires_at']);
        $loaded = $mapper->fromRow($row);
        self::assertEquals($session, $loaded->session());
        self::assertSame(7, $loaded->lockVersion());
        self::assertSame(2, $loaded->session()->getInteractionRevision()->value());
        $draft = $loaded->session()->getDraft();
        self::assertNotNull($draft);
        self::assertSame(self::COMMENT, $draft->comment());
        self::assertSame('UTC', $draft->expiresAt()->getTimezone()->getName());
        self::assertSame('2026-10-14T10:00:00.123456+00:00', $draft->expiresAt()->format('Y-m-d\TH:i:s.uP'));
        $this->assertMetadataTime($row, $now);
        self::assertSame($row, $this->loadRow());
    }

    /** @dataProvider storedStates */
    public function testRoundTripsStoredStatesWithoutRepair(
        TelegramContentMode $mode,
        TelegramSessionFlow $flow,
        ?string $subjectId,
        ?string $leadId,
    ): void {
        $draft = $flow === TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION
            ? TelegramSessionDraft::restore(
                self::COMMENT,
                new DateTimeImmutable('2000-01-01T10:00:00.654321', new DateTimeZone('UTC')),
            )
            : null;
        $session = TelegramBotSession::restore(
            new TelegramBotSessionId(self::SESSION_ID),
            self::PROFILE_ID,
            'session-mapping-test',
            PHP_INT_MAX,
            $mode,
            $flow,
            $subjectId,
            $leadId,
            $draft,
            new TelegramInteractionRevision(PHP_INT_MAX),
        );
        $now = new DateTimeImmutable('2026-10-13T10:00:00.123456', new DateTimeZone('UTC'));
        $mapper = new TelegramBotSessionMapper();
        $parameters = $mapper->toInsertParameters($session, $now);
        self::assertSame(1, $this->db->createCommand()->insert('{{%telegram_bot_sessions}}', $parameters)->execute());
        self::assertSame(1, $this->db->createCommand()->update(
            '{{%telegram_bot_sessions}}',
            ['lock_version' => PHP_INT_MAX],
            ['id' => self::SESSION_ID],
        )->execute());
        $row = $this->loadRow();
        $loaded = $mapper->fromRow($row);
        self::assertEquals($session, $loaded->session());
        self::assertSame(PHP_INT_MAX, $loaded->session()->getChatId());
        self::assertSame(PHP_INT_MAX, $loaded->session()->getInteractionRevision()->value());
        self::assertSame(PHP_INT_MAX, $loaded->lockVersion());
        $this->assertMetadataTime($row, $now);
        self::assertSame($row, $this->loadRow());
    }

    /** @return iterable<string, array{TelegramContentMode, TelegramSessionFlow, string|null, string|null}> */
    public static function storedStates(): iterable
    {
        yield 'browsing without context' => [TelegramContentMode::DEMO, TelegramSessionFlow::BROWSING, null, null];
        yield 'awaiting comment' => [TelegramContentMode::DEMO, TelegramSessionFlow::AWAITING_LEAD_COMMENT, self::SUBJECT_ID, null];
        yield 'expired confirmation' => [TelegramContentMode::DEMO, TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION, null, null];
        yield 'reserved without references' => [TelegramContentMode::CATALOG, TelegramSessionFlow::LEAD_REPLY, null, null];
        yield 'reserved with references' => [TelegramContentMode::CATALOG, TelegramSessionFlow::LEAD_REPLY, self::SUBJECT_ID, self::LEAD_ID];
    }

    public function testClearsDraftWithoutUpdatingExcludedFields(): void
    {
        $now = new DateTimeImmutable('2026-10-13T10:00:00.123456', new DateTimeZone('UTC'));
        $session = TelegramBotSession::restore(
            new TelegramBotSessionId(self::SESSION_ID),
            self::PROFILE_ID,
            'session-mapping-test',
            91,
            TelegramContentMode::DEMO,
            TelegramSessionFlow::AWAITING_LEAD_CONFIRMATION,
            self::SUBJECT_ID,
            self::LEAD_ID,
            TelegramSessionDraft::restore(
                self::COMMENT,
                new DateTimeImmutable('2000-01-01T10:00:00.654321', new DateTimeZone('UTC')),
            ),
            new TelegramInteractionRevision(4),
        );
        $mapper = new TelegramBotSessionMapper();
        self::assertSame(1, $this->db->createCommand()->insert(
            '{{%telegram_bot_sessions}}',
            $mapper->toInsertParameters($session, $now),
        )->execute());
        self::assertSame(1, $this->db->createCommand()->update(
            '{{%telegram_bot_sessions}}',
            ['lock_version' => 7],
            ['id' => self::SESSION_ID],
        )->execute());
        $before = $this->loadRow();
        $loaded = $mapper->fromRow($before);
        $result = $loaded->session()->expireDraft($now, null);
        self::assertSame(TelegramSessionTransitionOutcome::DRAFT_EXPIRED, $result->outcome);
        self::assertSame(1, $this->db->createCommand()->update(
            '{{%telegram_bot_sessions}}',
            $mapper->toUpdateParameters($loaded->session()),
            ['id' => self::SESSION_ID],
        )->execute());
        $after = $this->loadRow();
        self::assertNull($after['draft_comment']);
        self::assertNull($after['expires_at']);
        self::assertNull($after['content_subject_id']);
        self::assertSame('BROWSING', $after['flow_code']);
        self::assertSame(5, $mapper->fromRow($after)->session()->getInteractionRevision()->value());
        foreach (['id', 'telegram_identity_profile_id', 'bot_key', 'chat_id', 'content_mode',
            'agency_lead_id', 'lock_version', 'created_at', 'updated_at'] as $column) {
            self::assertSame($before[$column], $after[$column]);
        }
    }

    /** @return array<string, mixed> */
    private function loadRow(): array
    {
        $row = $this->db->createCommand(
            'SELECT id, telegram_identity_profile_id, bot_key, chat_id, flow_code, content_mode, '
            . 'content_subject_id, agency_lead_id, draft_comment, expires_at, interaction_revision, '
            . 'lock_version, created_at, updated_at FROM {{%telegram_bot_sessions}} WHERE id = :id',
            [':id' => self::SESSION_ID],
        )->queryOne();
        self::assertIsArray($row);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function assertMetadataTime(array $row, DateTimeImmutable $expected): void
    {
        foreach (['created_at', 'updated_at'] as $column) {
            self::assertIsString($row[$column]);
            self::assertSame($expected->format('U.u'), (new DateTimeImmutable($row[$column]))->format('U.u'));
        }
    }

    private function seedProfile(): void
    {
        self::assertSame(1, $this->db->createCommand()->insert('{{%user}}', [
            'id' => self::USER_ID, 'name' => null, 'surname' => null,
            'auth_key' => '0123456789abcdef0123456789abcdef',
        ])->execute());
        self::assertSame(1, $this->db->createCommand()->insert('{{%user_identity}}', [
            'id' => self::IDENTITY_ID, 'user_id' => self::USER_ID,
            'provider' => 'telegram', 'provider_client_id' => '1000000000000000001',
        ])->execute());
        self::assertSame(1, $this->db->createCommand()->insert('{{%telegram_identity_profiles}}', [
            'id' => self::PROFILE_ID, 'user_identity_id' => self::IDENTITY_ID, 'bot_status' => 'ACTIVE',
            'first_seen_at' => '2026-10-13T10:00:00+00:00', 'last_seen_at' => '2026-10-13T10:00:00+00:00',
        ])->execute());
    }

    private function rollBack(): void
    {
        if ($this->transaction !== null && $this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }
    }
}
