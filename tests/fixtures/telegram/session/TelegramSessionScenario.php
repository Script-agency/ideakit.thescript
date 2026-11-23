<?php

declare(strict_types=1);

namespace tests\fixtures\telegram\session;

use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\infrastructure\db\DbTelegramBotSessionRepository;
use modules\telegram\infrastructure\db\TelegramSessionDbFailure;
use modules\telegram\infrastructure\mapper\TelegramBotSessionMapper;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use tests\fixtures\platform\PlatformTestEnvironment;
use yii\db\Connection;
use yii\db\Transaction;

final class TelegramSessionScenario
{
    private function __construct(
        public readonly string $userId,
        public readonly string $identityId,
        public readonly string $profileId,
        public readonly string $botKey,
        public readonly int $chatId = 91,
    ) {
    }

    public static function connection(): Connection
    {
        $environment = PlatformTestEnvironment::databaseEnvironment();
        $db = new Connection([
            'dsn' => $environment['TEST_DB_DSN'],
            'username' => $environment['TEST_DB_USERNAME'],
            'password' => $environment['TEST_DB_PASSWORD'],
            'enableLogging' => false,
            'enableProfiling' => false,
        ]);
        self::assertDatabase($db);

        return $db;
    }

    public static function begin(Connection $db): Transaction
    {
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        $db->createCommand("SET LOCAL DateStyle = 'ISO, YMD'")->execute();
        $db->createCommand("SET LOCAL TIME ZONE 'UTC'")->execute();
        $db->createCommand("SET LOCAL lock_timeout = '2s'")->execute();
        $db->createCommand("SET LOCAL statement_timeout = '5s'")->execute();

        return $transaction;
    }

    public static function seed(Connection $db): self
    {
        self::assertDatabase($db);
        $scenario = new self(
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
            'session-test-' . Uuid::uuid7()->toString(),
        );
        $db->createCommand()->insert('{{%user}}', [
            'id' => $scenario->userId, 'name' => null, 'surname' => null,
            'auth_key' => '0123456789abcdef0123456789abcdef',
        ])->execute();
        $db->createCommand()->insert('{{%user_identity}}', [
            'id' => $scenario->identityId, 'user_id' => $scenario->userId, 'provider' => 'telegram',
            'provider_client_id' => (string) (intval(substr($scenario->identityId, -12), 16) + 1),
        ])->execute();
        $db->createCommand()->insert('{{%telegram_identity_profiles}}', [
            'id' => $scenario->profileId, 'user_identity_id' => $scenario->identityId, 'bot_status' => 'ACTIVE',
            'first_seen_at' => '2026-10-13T10:00:00+00:00', 'last_seen_at' => '2026-10-13T10:00:00+00:00',
        ])->execute();

        return $scenario;
    }

    public function candidate(?string $id = null): TelegramBotSession
    {
        return TelegramBotSession::createDemo(
            new TelegramBotSessionId($id ?? Uuid::uuid7()->toString()),
            $this->profileId,
            $this->botKey,
            $this->chatId,
            null,
        );
    }

    public static function clock(): FrozenTelegramSessionClock
    {
        return new FrozenTelegramSessionClock(new DateTimeImmutable('2026-10-13T10:00:00.123456', new DateTimeZone('UTC')));
    }

    public static function repository(Connection $db, FrozenTelegramSessionClock $clock): DbTelegramBotSessionRepository
    {
        return new DbTelegramBotSessionRepository($db, new TelegramBotSessionMapper(), $clock, new TelegramSessionDbFailure());
    }

    /** @return array<string, mixed> */
    public function snapshot(Connection $db): array
    {
        $row = $db->createCommand(
            'SELECT id, telegram_identity_profile_id, bot_key, chat_id, flow_code, content_mode, '
            . 'content_subject_id, agency_lead_id, draft_comment, expires_at, interaction_revision, '
            . 'lock_version, created_at, updated_at, ctid::text AS tuple_location '
            . 'FROM {{%telegram_bot_sessions}} WHERE bot_key = :bot AND chat_id = :chat',
            [':bot' => $this->botKey, ':chat' => $this->chatId],
        )->queryOne();
        if (!is_array($row)) {
            throw new RuntimeException('session_fixture_missing');
        }

        return $row;
    }

    public function cleanup(Connection $db): void
    {
        self::assertDatabase($db);
        $db->createCommand()->delete('{{%telegram_bot_sessions}}', ['bot_key' => $this->botKey])->execute();
        $db->createCommand()->delete('{{%telegram_identity_profiles}}', ['id' => $this->profileId])->execute();
        $db->createCommand()->delete('{{%user_identity}}', ['id' => $this->identityId])->execute();
        $db->createCommand()->delete('{{%user}}', ['id' => $this->userId])->execute();
    }

    private static function assertDatabase(Connection $db): void
    {
        if ($db->getDriverName() !== 'pgsql' || $db->createCommand('SELECT current_database()')->queryScalar() !== 'ideakit_test') {
            throw new RuntimeException('test_database_required');
        }
    }
}
