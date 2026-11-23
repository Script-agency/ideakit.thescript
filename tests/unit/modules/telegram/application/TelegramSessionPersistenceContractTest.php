<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\application;

use Codeception\Test\Unit;
use InvalidArgumentException;
use modules\telegram\application\dto\TelegramSessionCreationResult;
use modules\telegram\application\dto\VersionedTelegramBotSession;
use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use RuntimeException;

final class TelegramSessionPersistenceContractTest extends Unit
{
    /** @dataProvider lockVersions */
    public function testLoadedVersionDoesNotChangeWithDomainTransition(int $lockVersion): void
    {
        $session = self::session();
        $versioned = new VersionedTelegramBotSession($session, $lockVersion);

        $session->beginOrder('550e8400-e29b-41d4-a716-446655440000');

        self::assertSame($session, $versioned->session());
        self::assertSame($lockVersion, $versioned->lockVersion());
        self::assertSame(1, $versioned->session()->getInteractionRevision()->value());
    }

    /** @return iterable<string, array{int}> */
    public static function lockVersions(): iterable
    {
        yield 'initial' => [0];
        yield 'loaded' => [7];
        yield 'maximum' => [PHP_INT_MAX];
    }

    public function testRejectsNegativeStorageVersion(): void
    {
        try {
            new VersionedTelegramBotSession(self::session(), -1);
            self::fail('Expected invalid storage version.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('negative_telegram_session_lock_version', $failure->getMessage());
        }
    }

    public function testCreationOutcomesRetainTheSameVersionedSession(): void
    {
        $versioned = new VersionedTelegramBotSession(self::session(), 0);
        $created = new TelegramSessionCreationResult($versioned, true);
        $existing = new TelegramSessionCreationResult($versioned, false);

        self::assertTrue($created->created());
        self::assertFalse($existing->created());
        self::assertSame($versioned, $created->versionedSession());
        self::assertSame($versioned, $existing->versionedSession());
    }

    /** @dataProvider persistenceErrors */
    public function testErrorsExposeOnlyClosedCodeAndPreserveInternalCause(string $name, string $message): void
    {
        $code = TelegramSessionPersistenceErrorCode::from($message);
        $cause = new RuntimeException('synthetic-private-diagnostic');
        $failure = new TelegramSessionPersistenceException($code, $cause);

        self::assertSame($name, $code->name);
        self::assertSame($message, $failure->getMessage());
        self::assertSame(['errorCode' => $code], get_object_vars($failure));
        self::assertSame($cause, $failure->getPrevious());
        self::assertNull((new TelegramSessionPersistenceException($code))->getPrevious());
    }

    /** @return iterable<string, array{string, string}> */
    public static function persistenceErrors(): iterable
    {
        yield 'argument' => ['INVALID_ARGUMENT', 'telegram_session_invalid_argument'];
        yield 'record' => ['INVALID_RECORD', 'telegram_session_invalid_record'];
        yield 'transaction' => ['TRANSACTION_REQUIRED', 'telegram_session_transaction_required'];
        yield 'owner' => ['OWNER_MISMATCH', 'telegram_session_owner_mismatch'];
        yield 'mode' => ['MODE_MISMATCH', 'telegram_session_mode_mismatch'];
        yield 'missing' => ['NOT_FOUND', 'telegram_session_not_found'];
        yield 'conflict' => ['VERSION_CONFLICT', 'telegram_session_version_conflict'];
        yield 'overflow' => ['VERSION_OVERFLOW', 'telegram_session_version_overflow'];
        yield 'unavailable' => ['STORAGE_UNAVAILABLE', 'telegram_session_storage_unavailable'];
        yield 'failure' => ['STORAGE_FAILURE', 'telegram_session_storage_failure'];
    }

    private static function session(): TelegramBotSession
    {
        return TelegramBotSession::createDemo(
            new TelegramBotSessionId('01890f4d-3c2a-7f48-8c0b-123456789ab3'),
            '01890f4d-3c2a-7f48-8c0b-123456789ab2',
            'session-mapping-test',
            91,
            null,
        );
    }
}
