<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\db;

use modules\telegram\application\dto\TelegramSessionCreationResult;
use modules\telegram\application\dto\VersionedTelegramBotSession;
use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode as ErrorCode;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\application\port\ITelegramBotSessionRepository;
use modules\telegram\application\port\ITelegramSessionClock;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\infrastructure\mapper\TelegramBotSessionMapper;
use PDOException;
use yii\db\Connection;
use yii\db\Exception;

final class DbTelegramBotSessionRepository implements ITelegramBotSessionRepository
{
    private const COLUMNS = 'id, telegram_identity_profile_id, bot_key, chat_id, flow_code, content_mode, '
        . 'content_subject_id, agency_lead_id, draft_comment, expires_at, interaction_revision, '
        . 'lock_version, created_at, updated_at';

    public function __construct(
        private readonly Connection $db,
        private readonly TelegramBotSessionMapper $mapper,
        private readonly ITelegramSessionClock $clock,
        private readonly TelegramSessionDbFailure $failures,
    ) {
    }

    public function createIfAbsent(TelegramBotSession $candidate): TelegramSessionCreationResult
    {
        if ($candidate->getContentMode() !== TelegramContentMode::DEMO
            || $candidate->getFlow() !== TelegramSessionFlow::BROWSING
            || $candidate->getInteractionRevision()->value() !== 0
            || $candidate->getDraft() !== null || $candidate->getAgencyLeadId() !== null
        ) {
            throw new TelegramSessionPersistenceException(ErrorCode::INVALID_ARGUMENT);
        }

        try {
            $this->assertContext();
            $params = $this->mapper->toInsertParameters($candidate, $this->clock->now());
            $insertedId = $this->command(
                'INSERT INTO {{%telegram_bot_sessions}} (' . self::COLUMNS . ') VALUES ('
                . ':id, :telegram_identity_profile_id, :bot_key, :chat_id, :flow_code, :content_mode, '
                . ':content_subject_id, :agency_lead_id, :draft_comment, :expires_at, :interaction_revision, '
                . ':lock_version, :created_at, :updated_at) '
                . 'ON CONFLICT ON CONSTRAINT uq_telegram_bot_sessions_bot_key_chat_id DO NOTHING RETURNING id',
                $this->bindings($params),
            )->queryScalar();
            if ($insertedId !== false && $insertedId !== $candidate->getId()->value()) {
                throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
            }
            // A separate READ COMMITTED snapshot sees the committed conflict winner.
            $stored = $this->find('bot_key = :bot AND chat_id = :chat', [
                ':bot' => $candidate->getBotKey(), ':chat' => $candidate->getChatId(),
            ]);
            if ($stored === null) {
                throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
            }
            $session = $stored->session();
            $this->assertOwner($session, $candidate->getTelegramIdentityProfileId());
            if ($session->getContentMode() !== $candidate->getContentMode()) {
                throw new TelegramSessionPersistenceException(ErrorCode::MODE_MISMATCH);
            }
            if ($insertedId !== false && !$session->getId()->equals($candidate->getId())) {
                throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
            }

            return new TelegramSessionCreationResult($stored, $insertedId !== false);
        } catch (Exception|PDOException $failure) {
            throw $this->failures->translate($failure);
        }
    }

    public function loadForUpdate(string $botKey, int $chatId, string $expectedProfileId): ?VersionedTelegramBotSession
    {
        $expectedProfileId = strtolower($expectedProfileId);
        if (trim($botKey) !== $botKey || preg_match('/\A\S(?:.{0,62}\S)?\z/us', $botKey) !== 1
            || $chatId <= 0
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $expectedProfileId) !== 1
        ) {
            throw new TelegramSessionPersistenceException(ErrorCode::INVALID_ARGUMENT);
        }

        try {
            $this->assertContext();
            $stored = $this->find('bot_key = :bot AND chat_id = :chat', [':bot' => $botKey, ':chat' => $chatId]);
            if ($stored !== null) {
                $this->assertOwner($stored->session(), $expectedProfileId);
            }

            return $stored;
        } catch (Exception|PDOException $failure) {
            throw $this->failures->translate($failure);
        }
    }

    public function save(TelegramBotSession $session, int $expectedLockVersion): VersionedTelegramBotSession
    {
        if ($expectedLockVersion < 0) {
            throw new TelegramSessionPersistenceException(ErrorCode::INVALID_ARGUMENT);
        }

        try {
            $this->assertContext();
            $stored = $this->find('id = :id', [':id' => $session->getId()->value()]);
            if ($stored === null) {
                throw new TelegramSessionPersistenceException(ErrorCode::NOT_FOUND);
            }
            $current = $stored->session();
            $this->assertImmutableFields($current, $session);
            if ($stored->lockVersion() !== $expectedLockVersion) {
                throw new TelegramSessionPersistenceException(ErrorCode::VERSION_CONFLICT);
            }
            if ($session->getInteractionRevision()->value() < $current->getInteractionRevision()->value()) {
                throw new TelegramSessionPersistenceException(ErrorCode::INVALID_ARGUMENT);
            }
            $params = $this->mapper->toUpdateParameters($session);
            if ($params === $this->mapper->toUpdateParameters($current)) {
                return $stored;
            }
            if ($expectedLockVersion === PHP_INT_MAX) {
                throw new TelegramSessionPersistenceException(ErrorCode::VERSION_OVERFLOW);
            }
            $version = $expectedLockVersion + 1;
            $updatedAt = $this->mapper->toTimestampParameter($this->clock->now());
            $row = $this->command(
                'UPDATE {{%telegram_bot_sessions}} SET flow_code = :flow_code, content_subject_id = :content_subject_id, '
                . 'draft_comment = :draft_comment, expires_at = :expires_at, interaction_revision = :interaction_revision, '
                . 'lock_version = :version, updated_at = :updated_at '
                . 'WHERE id = :id AND telegram_identity_profile_id = :owner AND bot_key = :bot AND chat_id = :chat '
                . 'AND content_mode = :mode AND lock_version = :expected_version '
                . 'AND agency_lead_id IS NOT DISTINCT FROM CAST(:lead AS uuid) RETURNING ' . self::COLUMNS,
                $this->bindings($params) + [
                    ':version' => $version, ':updated_at' => $updatedAt, ':id' => $session->getId()->value(),
                    ':owner' => $session->getTelegramIdentityProfileId(), ':bot' => $session->getBotKey(),
                    ':chat' => $session->getChatId(), ':mode' => $session->getContentMode()->value,
                    ':expected_version' => $expectedLockVersion, ':lead' => $session->getAgencyLeadId(),
                ],
            )->queryOne();
            if ($row === false) {
                throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
            }
            $saved = $this->hydrate($row);
            $actual = $saved->session();
            if ($saved->lockVersion() !== $version || !$actual->getId()->equals($session->getId())
                || $actual->getTelegramIdentityProfileId() !== $session->getTelegramIdentityProfileId()
                || $actual->getBotKey() !== $session->getBotKey() || $actual->getChatId() !== $session->getChatId()
                || $actual->getContentMode() !== $session->getContentMode()
                || $actual->getAgencyLeadId() !== $session->getAgencyLeadId()
                || $this->mapper->toUpdateParameters($actual) !== $params
            ) {
                throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
            }

            return $saved;
        } catch (Exception|PDOException $failure) {
            throw $this->failures->translate($failure);
        }
    }

    private function assertContext(): void
    {
        if ($this->db->getDriverName() !== 'pgsql') {
            throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
        }
        if ($this->db->getTransaction() === null || $this->db->pdo === null || !$this->db->pdo->inTransaction()) {
            throw new TelegramSessionPersistenceException(ErrorCode::TRANSACTION_REQUIRED);
        }
        $ready = $this->command(<<<'SQL'
SELECT CASE WHEN current_setting('transaction_isolation') = 'read committed'
    AND current_setting('transaction_read_only') = 'off'
    AND split_part(current_setting('DateStyle'), ',', 1) = 'ISO'
    AND current_setting('TimeZone') = 'UTC'
    AND current_setting('lock_timeout')::interval > interval '0 seconds'
    AND current_setting('lock_timeout')::interval <= interval '2 seconds'
    AND current_setting('statement_timeout')::interval > current_setting('lock_timeout')::interval
    AND current_setting('statement_timeout')::interval <= interval '5 seconds'
THEN 1 ELSE 0 END
SQL)->queryScalar();
        if ($ready !== 1 && $ready !== '1') {
            throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
        }
    }

    private function assertOwner(TelegramBotSession $session, string $profile): void
    {
        if ($session->getTelegramIdentityProfileId() !== $profile) {
            throw new TelegramSessionPersistenceException(ErrorCode::OWNER_MISMATCH);
        }
    }

    private function assertImmutableFields(TelegramBotSession $current, TelegramBotSession $session): void
    {
        $this->assertOwner($current, $session->getTelegramIdentityProfileId());
        if (!$current->getId()->equals($session->getId())
            || $current->getBotKey() !== $session->getBotKey() || $current->getChatId() !== $session->getChatId()
        ) {
            throw new TelegramSessionPersistenceException(ErrorCode::INVALID_ARGUMENT);
        }
        if ($current->getContentMode() !== $session->getContentMode()) {
            throw new TelegramSessionPersistenceException(ErrorCode::MODE_MISMATCH);
        }
        if ($current->getAgencyLeadId() !== $session->getAgencyLeadId()) {
            throw new TelegramSessionPersistenceException(ErrorCode::INVALID_ARGUMENT);
        }
    }

    /** @param array<string, int|string|null> $params */
    private function find(string $predicate, array $params): ?VersionedTelegramBotSession
    {
        $row = $this->command('SELECT ' . self::COLUMNS . ' FROM {{%telegram_bot_sessions}} WHERE '
            . $predicate . ' FOR UPDATE', $params)->queryOne();

        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(mixed $row): VersionedTelegramBotSession
    {
        if (!is_array($row)) {
            throw new TelegramSessionPersistenceException(ErrorCode::STORAGE_FAILURE);
        }
        $record = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new TelegramSessionPersistenceException(ErrorCode::INVALID_RECORD);
            }
            $record[$key] = $value;
        }

        return $this->mapper->fromRow($record);
    }

    /** @param array<string, int|string|null> $params */
    private function command(string $sql, array $params = []): TelegramSessionDbCommand
    {
        $command = new TelegramSessionDbCommand(['db' => $this->db, 'sql' => $sql]);
        $command->bindValues($params);
        $command->noCache();

        return $command;
    }

    /**
     * @param array<string, int|string|null> $params
     * @return array<string, int|string|null>
     */
    private function bindings(array $params): array
    {
        $bindings = [];
        foreach ($params as $key => $value) {
            $bindings[':' . $key] = $value;
        }

        return $bindings;
    }
}
