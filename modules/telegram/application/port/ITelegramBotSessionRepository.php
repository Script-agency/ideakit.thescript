<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use modules\telegram\application\dto\TelegramSessionCreationResult;
use modules\telegram\application\dto\VersionedTelegramBotSession;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\domain\entity\TelegramBotSession;

/**
 * Internal Telegram write-side port. Every operation requires an active caller-owned
 * transaction on the same connection; it never begins, commits, rolls back or retries it.
 * Both framework and driver transactions must be active, read-write and READ COMMITTED,
 * with ISO dates and UTC timezone. The caller sets local positive lock timeout <= 2 seconds
 * and statement timeout greater than lock timeout and <= 5 seconds.
 */
interface ITelegramBotSessionRepository
{
    /**
     * Inserts an initial DEMO/BROWSING candidate or locks the existing bot/chat session.
     * An existing session is unchanged and must match the candidate's owner and mode.
     * A created result describes an uncommitted insert, not a committed transaction.
     *
     * @throws TelegramSessionPersistenceException
     */
    public function createIfAbsent(TelegramBotSession $candidate): TelegramSessionCreationResult;

    /**
     * Locks the exact bot/chat session for a trusted expected owner.
     * Returns null only for absence; owner mismatch and an invalid record are failures.
     * Reconstitution does not change state, draft expiry or either version.
     *
     * @throws TelegramSessionPersistenceException
     */
    public function loadForUpdate(
        string $botKey,
        int $chatId,
        string $expectedProfileId,
    ): ?VersionedTelegramBotSession;

    /**
     * Checks the loaded storage version even for unchanged state, which requires no write.
     * Rejects immutable or lead-reference changes and a decreasing interaction revision.
     * A change increments storage version once; the result does not imply commit.
     *
     * @throws TelegramSessionPersistenceException
     */
    public function save(
        TelegramBotSession $session,
        int $expectedLockVersion,
    ): VersionedTelegramBotSession;
}
