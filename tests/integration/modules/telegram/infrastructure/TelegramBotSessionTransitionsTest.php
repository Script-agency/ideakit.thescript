<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use DateInterval;
use DateTimeImmutable;
use modules\telegram\application\dto\VersionedTelegramBotSession;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\domain\enum\TelegramSessionFlow as Flow;
use modules\telegram\domain\enum\TelegramSessionTransitionOutcome as Outcome;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramSessionTransitionResult;
use modules\telegram\infrastructure\db\DbTelegramBotSessionRepository;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use tests\fixtures\telegram\session\FrozenTelegramSessionClock;
use tests\fixtures\telegram\session\TelegramSessionScenario;
use Throwable;
use yii\db\Connection;

final class TelegramBotSessionTransitionsTest extends Unit
{
    private const BROWSING_SUBJECT = '550e8400-e29b-41d4-a716-446655440000';
    private const ORDER_SUBJECT = '550e8400-e29b-41d4-a716-446655440001';
    private const RAW_COMMENT = "Synthetic\r\ncomment\t";
    private const COMMENT = "Synthetic\ncomment";
    private const SNAPSHOT_FIELDS = [
        'id', 'telegram_identity_profile_id', 'bot_key', 'chat_id', 'flow_code', 'content_mode',
        'content_subject_id', 'agency_lead_id', 'draft_comment', 'expires_at',
        'interaction_revision', 'lock_version', 'created_at', 'updated_at',
    ];

    private Connection $db;
    private Connection $observer;
    private FrozenTelegramSessionClock $clock;
    private DbTelegramBotSessionRepository $repository;
    private ?TelegramSessionScenario $scenario = null;

    public function testCommitsAndReloadsDemoOrderLifecycle(): void
    {
        $this->runScenario(function (): void {
            $created = $this->createSession();
            $initial = $this->observe();
            $this->assertState($created, Flow::BROWSING, self::BROWSING_SUBJECT, 0, 0, null, null);
            $this->assertInstant($this->clock->instant, $initial['created_at']);
            $this->assertInstant($this->clock->instant, $initial['updated_at']);

            $this->advance();
            $ordered = $this->change(fn (TelegramBotSession $session) => $session->beginOrder(self::ORDER_SUBJECT));
            $this->assertState($ordered, Flow::AWAITING_LEAD_COMMENT, self::ORDER_SUBJECT, 1, 1, null, null);
            $this->assertMetadata($initial, $this->observe());

            $this->advance();
            $acceptedAt = $this->clock->instant;
            $confirmed = $this->change(
                fn (TelegramBotSession $session) => $session->acceptComment(self::RAW_COMMENT, $acceptedAt),
            );
            $expiry = $acceptedAt->add(new DateInterval('PT24H'));
            $this->assertState($confirmed, Flow::AWAITING_LEAD_CONFIRMATION, self::ORDER_SUBJECT, 2, 2, self::COMMENT, $expiry);
            $confirmationRow = $this->observe();
            $this->assertMetadata($initial, $confirmationRow);
            self::assertSame(self::COMMENT, $confirmationRow['draft_comment']);
            $this->assertInstant($expiry, $confirmationRow['expires_at']);

            $this->advance();
            $reread = $this->reload();
            $this->assertState($reread, Flow::AWAITING_LEAD_CONFIRMATION, self::ORDER_SUBJECT, 2, 2, self::COMMENT, $expiry);
            self::assertSame($confirmationRow, $this->observe());

            self::assertTrue($this->clock->instant < $expiry);
            $finished = $this->change(fn (TelegramBotSession $session) => $session->confirmOrder(
                $session->getInteractionRevision(),
                $this->clock->instant,
                self::BROWSING_SUBJECT,
            ));
            $this->assertState($finished, Flow::BROWSING, self::BROWSING_SUBJECT, 3, 3, null, null);
            $row = $this->observe();
            $this->assertMetadata($initial, $row);
            self::assertNull($row['draft_comment']);
            self::assertNull($row['expires_at']);
        });
    }

    /** @dataProvider terminalActions */
    public function testCommitsTerminalTransition(string $action, Outcome $expected): void
    {
        $this->runScenario(function () use ($action, $expected): void {
            $prepared = $this->prepareConfirmation();
            $before = $this->observe();
            $draft = $prepared->session()->getDraft();
            self::assertNotNull($draft);
            if ($action === 'expired-confirmation') {
                $this->clock->instant = $draft->expiresAt();
            } else {
                $this->advance();
            }
            $finished = $this->change(function (TelegramBotSession $session) use ($action): TelegramSessionTransitionResult {
                self::assertNotNull($session->getDraft());

                return match ($action) {
                    'inline-cancel' => $session->cancelInline($session->getInteractionRevision(), self::BROWSING_SUBJECT),
                    'reply-cancel' => $session->cancelCurrentOrder(self::BROWSING_SUBJECT),
                    'restart' => $session->restartBrowsing(self::BROWSING_SUBJECT),
                    'expired-confirmation' => $session->confirmOrder(
                        $session->getInteractionRevision(),
                        $this->clock->instant,
                        self::BROWSING_SUBJECT,
                    ),
                    default => throw new RuntimeException('unsupported_terminal_test_action'),
                };
            }, $expected);
            $this->assertState($finished, Flow::BROWSING, self::BROWSING_SUBJECT, 3, 3, null, null);
            $after = $this->observe();
            $this->assertMetadata($before, $after);
            self::assertNull($after['draft_comment']);
            self::assertNull($after['expires_at']);
        });
    }

    /** @return iterable<string, array{string, Outcome}> */
    public static function terminalActions(): iterable
    {
        yield 'inline cancellation' => ['inline-cancel', Outcome::APPLIED];
        yield 'reply cancellation' => ['reply-cancel', Outcome::APPLIED];
        yield 'restart browsing' => ['restart', Outcome::APPLIED];
        yield 'expired confirmation' => ['expired-confirmation', Outcome::DRAFT_EXPIRED];
    }

    public function testStaleConfirmationPreservesNewCommittedDraft(): void
    {
        $this->runScenario(function (): void {
            $first = $this->prepareConfirmation();
            $oldRevision = $first->session()->getInteractionRevision();
            $oldDraft = $first->session()->getDraft();
            self::assertNotNull($oldDraft);
            $this->advance();
            $this->change(fn (TelegramBotSession $session) => $session->confirmOrder(
                $oldRevision,
                $this->clock->instant,
                self::BROWSING_SUBJECT,
            ));
            $this->clock->instant = $this->clock->instant->add(new DateInterval('P2D'));
            $this->change(fn (TelegramBotSession $session) => $session->beginOrder(self::ORDER_SUBJECT));
            $this->advance();
            $newComment = 'Synthetic new draft';
            $newAcceptedAt = $this->clock->instant;
            $newExpiry = $newAcceptedAt->add(new DateInterval('PT24H'));
            $current = $this->change(
                fn (TelegramBotSession $session) => $session->acceptComment($newComment, $newAcceptedAt),
            );
            $this->assertState($current, Flow::AWAITING_LEAD_CONFIRMATION, self::ORDER_SUBJECT, 5, 5, $newComment, $newExpiry);
            $before = $this->observe();
            $this->advance();
            self::assertTrue($this->clock->instant >= $oldDraft->expiresAt());
            self::assertTrue($this->clock->instant < $newExpiry);
            $this->committed(function () use ($oldRevision): VersionedTelegramBotSession {
                $loaded = $this->load();
                $result = $loaded->session()->confirmOrder($oldRevision, $this->clock->instant, self::BROWSING_SUBJECT);
                self::assertSame(Outcome::STALE_REVISION, $result->outcome);
                self::assertFalse($result->requiresSave());

                return $loaded;
            });
            self::assertSame($before, $this->observe());
            $this->assertState(
                $this->reload(),
                Flow::AWAITING_LEAD_CONFIRMATION,
                self::ORDER_SUBJECT,
                5,
                5,
                $newComment,
                $newExpiry,
            );
        });
    }

    public function testRollbackRestoresClearedCommittedDraft(): void
    {
        $this->runScenario(function (): void {
            $prepared = $this->prepareConfirmation();
            $draft = $prepared->session()->getDraft();
            self::assertNotNull($draft);
            $before = $this->observe();
            $this->advance();
            $transaction = TelegramSessionScenario::begin($this->db);
            try {
                $loaded = $this->load();
                $result = $loaded->session()->cancelCurrentOrder(self::BROWSING_SUBJECT);
                self::assertSame(Outcome::APPLIED, $result->outcome);
                self::assertTrue($result->requiresSave());
                $saved = $this->repository->save($loaded->session(), $loaded->lockVersion());
                $this->assertState($saved, Flow::BROWSING, self::BROWSING_SUBJECT, 3, 3, null, null);
                $uncommitted = $this->snapshot($this->db);
                self::assertSame('BROWSING', $uncommitted['flow_code']);
                self::assertSame(3, $uncommitted['interaction_revision']);
                self::assertSame(3, $uncommitted['lock_version']);
                self::assertNull($uncommitted['draft_comment']);
                self::assertNull($uncommitted['expires_at']);
                self::assertNotSame($before, $uncommitted);
                self::assertSame($before, $this->observe());
                $transaction->rollBack();
            } catch (Throwable $failure) {
                try {
                    if ($transaction->getIsActive()) {
                        $transaction->rollBack();
                    }
                } finally {
                    throw $failure;
                }
            }
            self::assertSame($before, $this->observe());
            $this->assertState(
                $this->reload(),
                Flow::AWAITING_LEAD_CONFIRMATION,
                self::ORDER_SUBJECT,
                2,
                2,
                self::COMMENT,
                $draft->expiresAt(),
            );
        });
    }

    /** @param callable(): void $assertions */
    private function runScenario(callable $assertions): void
    {
        try {
            $this->db = TelegramSessionScenario::connection();
            $this->observer = TelegramSessionScenario::connection();
            $this->clock = TelegramSessionScenario::clock();
            $this->repository = TelegramSessionScenario::repository($this->db, $this->clock);
            $transaction = TelegramSessionScenario::begin($this->db);
            $this->scenario = TelegramSessionScenario::seed($this->db);
            $transaction->commit();
            $assertions();
        } catch (Throwable $failure) {
            try {
                $this->cleanup();
            } finally {
                throw $failure;
            }
        }
        $this->cleanup();
    }

    private function createSession(): VersionedTelegramBotSession
    {
        $this->committed(function (): VersionedTelegramBotSession {
            $scenario = $this->scenario();
            $candidate = TelegramBotSession::createDemo(
                new TelegramBotSessionId(Uuid::uuid7()->toString()),
                $scenario->profileId,
                $scenario->botKey,
                $scenario->chatId,
                self::BROWSING_SUBJECT,
            );

            $result = $this->repository->createIfAbsent($candidate);
            self::assertTrue($result->created());

            return $result->versionedSession();
        });

        return $this->reload();
    }

    private function prepareConfirmation(): VersionedTelegramBotSession
    {
        $this->createSession();
        $this->advance();
        $this->change(fn (TelegramBotSession $session) => $session->beginOrder(self::ORDER_SUBJECT));
        $this->advance();

        return $this->change(
            fn (TelegramBotSession $session) => $session->acceptComment(self::RAW_COMMENT, $this->clock->instant),
        );
    }

    /**
     * @param callable(TelegramBotSession): TelegramSessionTransitionResult $transition
     */
    private function change(callable $transition, Outcome $expected = Outcome::APPLIED): VersionedTelegramBotSession
    {
        $this->committed(function () use ($transition, $expected): VersionedTelegramBotSession {
            $loaded = $this->load();
            $result = $transition($loaded->session());
            self::assertSame($expected, $result->outcome);
            self::assertTrue($result->requiresSave());

            return $this->repository->save($loaded->session(), $loaded->lockVersion());
        });

        return $this->reload();
    }

    private function reload(): VersionedTelegramBotSession
    {
        return $this->committed(fn (): VersionedTelegramBotSession => $this->load());
    }

    private function load(): VersionedTelegramBotSession
    {
        $scenario = $this->scenario();
        $loaded = $this->repository->loadForUpdate($scenario->botKey, $scenario->chatId, $scenario->profileId);
        self::assertNotNull($loaded);

        return $loaded;
    }

    /** @param callable(): VersionedTelegramBotSession $operation */
    private function committed(callable $operation): VersionedTelegramBotSession
    {
        $transaction = TelegramSessionScenario::begin($this->db);
        try {
            $result = $operation();
            $transaction->commit();

            return $result;
        } catch (Throwable $failure) {
            try {
                if ($transaction->getIsActive()) {
                    $transaction->rollBack();
                }
            } finally {
                throw $failure;
            }
        }
    }

    /** @return array<string, mixed> */
    private function observe(): array
    {
        $transaction = TelegramSessionScenario::begin($this->observer);
        try {
            $row = $this->snapshot($this->observer);
            $transaction->commit();

            return $row;
        } catch (Throwable $failure) {
            try {
                if ($transaction->getIsActive()) {
                    $transaction->rollBack();
                }
            } finally {
                throw $failure;
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Connection $db): array
    {
        $snapshot = array_intersect_key(
            $this->scenario()->snapshot($db),
            array_fill_keys(self::SNAPSHOT_FIELDS, true),
        );
        self::assertCount(count(self::SNAPSHOT_FIELDS), $snapshot);

        return $snapshot;
    }

    private function scenario(): TelegramSessionScenario
    {
        if ($this->scenario === null) {
            throw new RuntimeException('session_transition_fixture_required');
        }

        return $this->scenario;
    }

    private function advance(): void
    {
        $this->clock->instant = $this->clock->instant->add(new DateInterval('PT1S'));
    }

    private function assertState(
        VersionedTelegramBotSession $stored,
        Flow $flow,
        string $subject,
        int $revision,
        int $version,
        ?string $comment,
        ?DateTimeImmutable $expiry,
    ): void {
        $session = $stored->session();
        self::assertSame(TelegramContentMode::DEMO, $session->getContentMode());
        self::assertSame($flow, $session->getFlow());
        self::assertSame($subject, $session->getContentSubjectId());
        self::assertSame($revision, $session->getInteractionRevision()->value());
        self::assertSame($version, $stored->lockVersion());
        $draft = $session->getDraft();
        if ($comment === null) {
            self::assertNull($draft);
            self::assertNull($expiry);

            return;
        }
        self::assertNotNull($draft);
        self::assertNotNull($expiry);
        self::assertSame($comment, $draft->comment());
        self::assertSame('UTC', $draft->expiresAt()->getTimezone()->getName());
        self::assertSame($expiry->format('U.u'), $draft->expiresAt()->format('U.u'));
    }

    /**
     * @param array<string, mixed> $initial
     * @param array<string, mixed> $actual
     */
    private function assertMetadata(array $initial, array $actual): void
    {
        foreach (['id', 'telegram_identity_profile_id', 'bot_key', 'chat_id', 'content_mode', 'agency_lead_id', 'created_at'] as $key) {
            self::assertSame($initial[$key], $actual[$key]);
        }
        self::assertNull($actual['agency_lead_id']);
        $this->assertInstant($this->clock->instant, $actual['updated_at']);
    }

    private function assertInstant(DateTimeImmutable $expected, mixed $actual): void
    {
        self::assertIsString($actual);
        $instant = new DateTimeImmutable($actual);
        self::assertSame(0, $instant->getOffset());
        self::assertSame($expected->format('U.u'), $instant->format('U.u'));
    }

    private function cleanup(): void
    {
        $failure = null;
        $connections = [];
        if (isset($this->db)) {
            $connections[] = $this->db;
        }
        if (isset($this->observer)) {
            $connections[] = $this->observer;
        }
        foreach ($connections as $connection) {
            try {
                $connection->getTransaction()?->rollBack();
            } catch (Throwable $cause) {
                $failure ??= $cause;
            }
        }
        if ($this->scenario !== null && isset($this->db)) {
            try {
                $transaction = TelegramSessionScenario::begin($this->db);
                try {
                    $this->scenario->cleanup($this->db);
                    $transaction->commit();
                } catch (Throwable $cause) {
                    try {
                        if ($transaction->getIsActive()) {
                            $transaction->rollBack();
                        }
                    } finally {
                        throw $cause;
                    }
                }
            } catch (Throwable $cause) {
                $failure ??= $cause;
            }
        }
        foreach ($connections as $connection) {
            try {
                $connection->close();
            } catch (Throwable $cause) {
                $failure ??= $cause;
            }
        }
        $this->scenario = null;
        if ($failure !== null) {
            throw new RuntimeException('session_transition_cleanup_failed', 0, $failure);
        }
    }
}
