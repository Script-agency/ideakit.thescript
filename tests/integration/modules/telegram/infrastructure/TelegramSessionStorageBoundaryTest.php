<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use DateTimeImmutable;
use LogicException;
use modules\telegram\application\dto\VersionedTelegramBotSession;
use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode as ErrorCode;
use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\application\port\ITelegramSessionClock;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\infrastructure\db\DbTelegramBotSessionRepository;
use modules\telegram\infrastructure\db\TelegramSessionDbCommand;
use modules\telegram\infrastructure\db\TelegramSessionDbFailure;
use modules\telegram\infrastructure\mapper\TelegramBotSessionMapper;
use Ramsey\Uuid\Uuid;
use tests\fixtures\telegram\session\FrozenTelegramSessionClock;
use tests\fixtures\telegram\session\RecordingSessionQueryCache;
use tests\fixtures\telegram\session\TelegramSessionScenario;
use Throwable;
use yii\BaseYii;
use yii\db\Connection;
use yii\db\Exception;
use yii\log\Logger;

final class TelegramSessionStorageBoundaryTest extends Unit
{
    private Connection $db;
    private TelegramSessionScenario $scenario;
    private FrozenTelegramSessionClock $clock;
    private DbTelegramBotSessionRepository $repository;

    protected function _before(): void
    {
        $this->db = TelegramSessionScenario::connection();
        try {
            $transaction = TelegramSessionScenario::begin($this->db);
            $this->scenario = TelegramSessionScenario::seed($this->db);
            $this->clock = TelegramSessionScenario::clock();
            $this->repository = TelegramSessionScenario::repository($this->db, $this->clock);
            $this->repository->createIfAbsent($this->scenario->candidate());
            $transaction->commit();
        } catch (Throwable $failure) {
            try {
                $this->cleanup();
            } finally {
                throw $failure;
            }
        }
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** @dataProvider invalidContexts */
    public function testRejectsInvalidContextWithoutChangingIt(string $setting): void
    {
        $snapshot = $this->scenario->snapshot($this->db);
        if (str_starts_with($setting, 'SET TRANSACTION')) {
            $transaction = $this->db->beginTransaction();
            // Even prepared SET LOCAL commands must follow the isolation selection.
            $this->db->createCommand($setting)->execute();
            $this->db->createCommand("SET LOCAL DateStyle = 'ISO, YMD'")->execute();
            $this->db->createCommand("SET LOCAL TIME ZONE 'UTC'")->execute();
            $this->db->createCommand("SET LOCAL lock_timeout = '2s'")->execute();
            $this->db->createCommand("SET LOCAL statement_timeout = '5s'")->execute();
        } else {
            $transaction = TelegramSessionScenario::begin($this->db);
            $this->db->createCommand($setting)->execute();
        }
        $sql = "SELECT current_setting('transaction_isolation') AS isolation, "
            . "current_setting('transaction_read_only') AS read_only, current_setting('DateStyle') AS date_style, "
            . "current_setting('TimeZone') AS timezone, current_setting('lock_timeout') AS lock_timeout, "
            . "current_setting('statement_timeout') AS statement_timeout";
        $before = $this->db->createCommand($sql)->queryOne();
        $this->assertFailure(ErrorCode::STORAGE_FAILURE, fn () => $this->load());
        self::assertSame($before, $this->db->createCommand($sql)->queryOne());
        self::assertSame($transaction, $this->db->getTransaction());
        self::assertTrue($transaction->getIsActive());
        $transaction->rollBack();
        $observer = TelegramSessionScenario::connection();
        try {
            self::assertSame($snapshot, $this->scenario->snapshot($observer));
        } finally {
            $observer->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidContexts(): iterable
    {
        yield 'repeatable read' => ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'];
        yield 'read only' => ['SET TRANSACTION READ ONLY'];
        yield 'non ISO' => ["SET LOCAL DateStyle = 'SQL, DMY'"];
        yield 'non UTC' => ["SET LOCAL TIME ZONE 'Europe/Moscow'"];
        yield 'unbounded lock' => ["SET LOCAL lock_timeout = '0'"];
        yield 'unbounded statement' => ["SET LOCAL statement_timeout = '0'"];
        yield 'long lock' => ["SET LOCAL lock_timeout = '3s'"];
        yield 'long statement' => ["SET LOCAL statement_timeout = '6s'"];
        yield 'inverted bounds' => ["SET LOCAL statement_timeout = '1s'"];
    }

    /** @dataProvider transactionContexts */
    public function testRequiresYiiAndPdoTransactionWithoutTakingOwnership(string $context): void
    {
        $snapshot = $this->scenario->snapshot($this->db);
        $session = (new TelegramBotSessionMapper())->fromRow($snapshot)->session();
        $pdo = $this->db->pdo;
        self::assertNotNull($pdo);
        if ($context === 'pdo only') {
            $pdo->beginTransaction();
        } elseif ($context === 'ended pdo') {
            TelegramSessionScenario::begin($this->db);
            $pdo->rollBack();
        }
        $calls = $this->clock->calls;
        foreach ([
            fn () => $this->load(),
            fn () => $this->repository->createIfAbsent($this->scenario->candidate()),
            fn () => $this->repository->save($session, 0),
        ] as $operation) {
            $this->assertFailure(ErrorCode::TRANSACTION_REQUIRED, $operation);
            self::assertSame($context === 'pdo only', $pdo->inTransaction());
            self::assertSame($pdo, $this->db->pdo);
        }
        self::assertSame($calls, $this->clock->calls);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->getTransaction()?->rollBack();
        self::assertSame($snapshot, $this->scenario->snapshot($this->db));
    }

    /** @return iterable<string, array{string}> */
    public static function transactionContexts(): iterable
    {
        foreach (['none', 'pdo only', 'ended pdo'] as $context) {
            yield $context => [$context];
        }
    }

    public function testRechecksContextOnEveryCallAndPreservesNestedLevel(): void
    {
        $transaction = TelegramSessionScenario::begin($this->db);
        self::assertNotNull($this->load());
        $this->db->createCommand("SET LOCAL lock_timeout = '0'")->execute();
        $this->assertFailure(ErrorCode::STORAGE_FAILURE, fn () => $this->load());
        $this->db->createCommand("SET LOCAL lock_timeout = '100ms'")->execute();
        $this->db->createCommand("SET LOCAL statement_timeout = '500ms'")->execute();
        $nested = $this->db->beginTransaction();
        self::assertSame(2, $nested->getLevel());
        self::assertNotNull($this->load());
        self::assertSame(2, $nested->getLevel());
        $nested->rollBack();
        self::assertSame(1, $transaction->getLevel());
        $transaction->rollBack();
        $this->assertFailure(ErrorCode::TRANSACTION_REQUIRED, fn () => $this->load());
    }

    /** @dataProvider integrityFailures */
    public function testIntegrityFailureIsNotDuplicateAndDoesNotRollback(string $kind, string $state): void
    {
        $before = $this->scenario->snapshot($this->db);
        self::assertIsString($before['id']);
        $candidate = TelegramBotSession::createDemo(
            new TelegramBotSessionId($kind === 'pk' ? $before['id'] : Uuid::uuid7()->toString()),
            $kind === 'fk' ? Uuid::uuid7()->toString() : $this->scenario->profileId,
            $this->scenario->botKey,
            $this->scenario->chatId + 1,
            null,
        );
        $transaction = TelegramSessionScenario::begin($this->db);
        $originalLogger = BaseYii::getLogger();
        $logger = new Logger(['flushInterval' => 0]);
        $flags = [$this->db->enableLogging, $this->db->enableProfiling];
        BaseYii::setLogger($logger);
        try {
            $this->db->enableLogging = true;
            $this->db->enableProfiling = true;
            $failure = $this->assertFailure(ErrorCode::STORAGE_FAILURE, fn () => $this->repository->createIfAbsent($candidate));
            self::assertTrue($logger->messages === [], 'Failed session queries must not enter the application logger.');
            self::assertTrue($this->db->enableLogging);
            self::assertTrue($this->db->enableProfiling);
        } finally {
            [$this->db->enableLogging, $this->db->enableProfiling] = $flags;
            BaseYii::setLogger($originalLogger);
        }
        $cause = $failure->getPrevious();
        self::assertInstanceOf(Exception::class, $cause);
        self::assertSame($state, $cause->errorInfo[0]);
        self::assertSame($transaction, $this->db->getTransaction());
        self::assertTrue($transaction->getIsActive());
        self::assertTrue($this->db->pdo->inTransaction());
        $transaction->rollBack();
        $observer = TelegramSessionScenario::connection();
        try {
            self::assertSame($before, $this->scenario->snapshot($observer));
            self::assertFalse($observer->createCommand(
                'SELECT id FROM {{%telegram_bot_sessions}} WHERE bot_key = :bot AND chat_id = :chat',
                [':bot' => $candidate->getBotKey(), ':chat' => $candidate->getChatId()],
            )->queryScalar());
        } finally {
            $observer->close();
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function integrityFailures(): iterable
    {
        yield 'primary key' => ['pk', '23505'];
        yield 'foreign key' => ['fk', '23503'];
    }

    public function testDoesNotTranslateProgrammingFailure(): void
    {
        $cause = new LogicException('synthetic-private-clock-marker');
        $clock = new class ($cause) implements ITelegramSessionClock {
            public function __construct(private readonly LogicException $failure)
            {
            }

            public function now(): DateTimeImmutable
            {
                throw $this->failure;
            }
        };
        $repository = new DbTelegramBotSessionRepository($this->db, new TelegramBotSessionMapper(), $clock, new TelegramSessionDbFailure());
        $transaction = TelegramSessionScenario::begin($this->db);
        try {
            $repository->createIfAbsent($this->scenario->candidate());
            self::fail('Expected the original programming failure.');
        } catch (LogicException $failure) {
            self::assertSame($cause, $failure);
        }
        self::assertSame($transaction, $this->db->getTransaction());
        self::assertTrue($transaction->getIsActive());
    }

    public function testLocalCommandsBypassLoggingProfilingAndQueryCache(): void
    {
        TelegramSessionScenario::begin($this->db);
        $originalLogger = BaseYii::getLogger();
        $logger = new Logger(['flushInterval' => 0]);
        $db = $this->db;
        $flags = [$db->enableLogging, $db->enableProfiling, $db->queryCache, $db->enableQueryCache, $db->commandClass];
        $cache = new RecordingSessionQueryCache();
        BaseYii::setLogger($logger);
        try {
            $db->enableLogging = true;
            $db->enableProfiling = true;
            $db->enableQueryCache = true;
            $db->queryCache = $cache;
            $saved = $db->cache(function () {
                $candidate = TelegramBotSession::createDemo(
                    new TelegramBotSessionId(Uuid::uuid7()->toString()),
                    $this->scenario->profileId,
                    $this->scenario->botKey,
                    $this->scenario->chatId + 1,
                    null,
                );
                $created = $this->repository->createIfAbsent($candidate);
                self::assertTrue($created->created());
                self::assertFalse($this->repository->createIfAbsent($candidate)->created());
                $session = $created->versionedSession()->session();
                $session->beginOrder('550e8400-e29b-41d4-a716-446655440000');
                $session->acceptComment('synthetic-private-marker', $this->clock->instant);
                $saved = $this->repository->save($session, 0);
                $loaded = $this->repository->loadForUpdate($session->getBotKey(), $session->getChatId(), $session->getTelegramIdentityProfileId());
                self::assertEquals($saved, $loaded);
                $position = new TelegramSessionDbCommand([
                    'db' => $this->db,
                    'sql' => 'SELECT ctid FROM {{%telegram_bot_sessions}} WHERE id = :id',
                ]);
                $position->bindValue(':id', $session->getId()->value());
                $position->noCache();
                $before = $position->queryScalar();
                self::assertIsString($before);
                $calls = $this->clock->calls;
                self::assertSame(1, $this->repository->save($saved->session(), 1)->lockVersion());
                self::assertSame($calls, $this->clock->calls);
                self::assertSame($before, $position->queryScalar());
                $this->assertFailure(ErrorCode::VERSION_CONFLICT, fn () => $this->repository->save($session, 0));

                return $saved;
            }, 60);
            self::assertSame(1, $saved->lockVersion());
            self::assertSame(0, $cache->writes);
            self::assertTrue($logger->messages === [], 'Session queries must not enter the application logger.');
            self::assertTrue($db->enableLogging);
            self::assertTrue($db->enableProfiling);
            self::assertTrue($db->enableQueryCache);
            self::assertSame($cache, $db->queryCache);
            self::assertSame($flags[4], $db->commandClass);
        } finally {
            [$db->enableLogging, $db->enableProfiling, $db->queryCache, $db->enableQueryCache] = $flags;
            BaseYii::setLogger($originalLogger);
        }
        self::assertSame('synthetic-private-marker', $db->createCommand(
            'SELECT draft_comment FROM {{%telegram_bot_sessions}} WHERE id = :id',
            [':id' => $saved->session()->getId()->value()],
        )->queryScalar());
    }

    private function load(): ?VersionedTelegramBotSession
    {
        return $this->repository->loadForUpdate($this->scenario->botKey, $this->scenario->chatId, $this->scenario->profileId);
    }

    private function assertFailure(ErrorCode $code, callable $operation): TelegramSessionPersistenceException
    {
        try {
            $operation();
            self::fail('Expected typed session storage refusal.');
        } catch (TelegramSessionPersistenceException $failure) {
            self::assertSame($code, $failure->errorCode);
            self::assertSame($code->value, $failure->getMessage());

            return $failure;
        }
    }

    private function cleanup(): void
    {
        if (!isset($this->db)) {
            return;
        }
        try {
            $this->db->getTransaction()?->rollBack();
            if ($this->db->pdo?->inTransaction()) {
                $this->db->pdo->rollBack();
            }
            $transaction = TelegramSessionScenario::begin($this->db);
            try {
                if (isset($this->scenario)) {
                    $this->scenario->cleanup($this->db);
                }
                $transaction->commit();
            } finally {
                if ($transaction->getIsActive()) {
                    $transaction->rollBack();
                }
            }
        } finally {
            $this->db->close();
        }
    }
}
