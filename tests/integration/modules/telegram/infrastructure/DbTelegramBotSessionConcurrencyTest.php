<?php

declare(strict_types=1);

namespace tests\integration\modules\telegram\infrastructure;

use Codeception\Test\Unit;
use RuntimeException;
use tests\fixtures\platform\PlatformTestEnvironment;
use tests\fixtures\telegram\session\TelegramSessionScenario;
use Throwable;
use yii\db\Connection;

final class DbTelegramBotSessionConcurrencyTest extends Unit
{
    private Connection $db;
    private TelegramSessionScenario $scenario;
    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $children = [];
    /** @var list<TelegramSessionScenario> */
    private array $extraScenarios = [];

    protected function _before(): void
    {
        $this->db = TelegramSessionScenario::connection();
        try {
            $transaction = TelegramSessionScenario::begin($this->db);
            $this->scenario = TelegramSessionScenario::seed($this->db);
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

    /** @dataProvider creationResolutions */
    public function testConcurrentCreation(string $resolution): void
    {
        $holder = $this->start('holder-create');
        $contender = $this->start('contender-create');
        $this->signal($holder, 'GO');
        self::assertSame('LOCKED', $this->line($holder));
        $this->signal($contender, 'GO');
        $this->assertWaiting();
        $this->signal($holder, $resolution);
        $first = $this->receipt($holder);
        $second = $this->receipt($contender);
        self::assertTrue($first['created']);
        self::assertSame($resolution === 'ROLLBACK', $second['created']);
        self::assertSame($resolution === 'COMMIT', $first['id'] === $second['id']);
        self::assertSame($this->scenario->snapshot($this->db)['id'], $second['id']);
        self::assertSame(0, $second['lock_version']);
        self::assertSame(0, $second['revision']);
        $count = $this->db->createCommand(
            'SELECT count(*) FROM {{%telegram_bot_sessions}} WHERE bot_key = :bot AND chat_id = :chat',
            [':bot' => $this->scenario->botKey, ':chat' => $this->scenario->chatId],
        )->queryScalar();
        self::assertTrue($count === 1 || $count === '1');
        $this->assertSuccessfulExit($holder);
        $this->assertSuccessfulExit($contender);
    }

    /** @return iterable<string, array{string}> */
    public static function creationResolutions(): iterable
    {
        yield 'winner commits' => ['COMMIT'];
        yield 'winner rolls back' => ['ROLLBACK'];
    }

    public function testLockTimeoutDoesNotReturnMissing(): void
    {
        $this->seedSession();
        $before = $this->scenario->snapshot($this->db);
        $holder = $this->start('holder-restart');
        $contender = $this->start('contender-load');
        $this->signal($holder, 'GO');
        self::assertSame('LOCKED', $this->line($holder));
        $this->signal($contender, 'GO');
        $this->assertWaiting();
        self::assertSame('ERROR:telegram_session_storage_unavailable', $this->line($contender));
        $this->signal($holder, 'ROLLBACK');
        self::assertSame('ROLLED_BACK', $this->receipt($holder)['outcome']);
        self::assertSame($before, $this->scenario->snapshot($this->db));
        $this->assertSuccessfulExit($holder);
        $this->assertSuccessfulExit($contender);
    }

    public function testWaiterReadsFreshVersionAfterCommit(): void
    {
        $this->seedSession();
        $holder = $this->start('holder-restart');
        $contender = $this->start('contender-load');
        $this->signal($holder, 'GO');
        self::assertSame('LOCKED', $this->line($holder));
        $this->signal($contender, 'GO');
        $this->assertWaiting();
        $this->signal($holder, 'COMMIT');
        self::assertSame('APPLIED', $this->receipt($holder)['outcome']);
        $receipt = $this->receipt($contender);
        self::assertSame('LOADED', $receipt['outcome']);
        self::assertSame(1, $receipt['revision']);
        self::assertSame(1, $receipt['lock_version']);
        $this->assertSuccessfulExit($holder);
        $this->assertSuccessfulExit($contender);
    }

    public function testDifferentPairsDoNotUseGlobalLock(): void
    {
        $this->seedSession();
        $transaction = TelegramSessionScenario::begin($this->db);
        $other = TelegramSessionScenario::seed($this->db);
        $this->extraScenarios[] = $other;
        TelegramSessionScenario::repository($this->db, TelegramSessionScenario::clock())->createIfAbsent($other->candidate());
        $transaction->commit();
        $holder = $this->start('holder-restart');
        $contender = $this->start('contender-restart', $other);
        $this->signal($holder, 'GO');
        self::assertSame('LOCKED', $this->line($holder));
        $this->signal($contender, 'GO');
        $receipt = $this->receipt($contender);
        self::assertSame('APPLIED', $receipt['outcome']);
        self::assertSame(1, $receipt['lock_version']);
        self::assertSame($other->snapshot($this->db)['id'], $receipt['id']);
        $this->assertSuccessfulExit($contender);
        $this->signal($holder, 'ROLLBACK');
        self::assertSame('ROLLED_BACK', $this->receipt($holder)['outcome']);
        $this->assertSuccessfulExit($holder);
    }

    /** @dataProvider transitionOrders */
    public function testSerializesRestartAndConfirmation(string $first, string $second, string $outcome, int $revision, int $version): void
    {
        $this->seedSession(true);
        $holder = $this->start('holder-' . $first);
        $contender = $this->start('contender-' . $second);
        $this->signal($holder, 'GO');
        self::assertSame('LOCKED', $this->line($holder));
        $this->signal($contender, 'GO');
        $this->assertWaiting();
        $this->signal($holder, 'COMMIT');
        self::assertSame('APPLIED', $this->receipt($holder)['outcome']);
        $receipt = $this->receipt($contender);
        self::assertSame($outcome, $receipt['outcome']);
        self::assertSame($revision, $receipt['revision']);
        self::assertSame($version, $receipt['lock_version']);
        $row = $this->scenario->snapshot($this->db);
        self::assertSame('BROWSING', $row['flow_code']);
        self::assertNull($row['draft_comment']);
        self::assertNull($row['expires_at']);
        self::assertTrue($row['interaction_revision'] === $revision || $row['interaction_revision'] === (string) $revision);
        self::assertTrue($row['lock_version'] === $version || $row['lock_version'] === (string) $version);
        $this->assertSuccessfulExit($holder);
        $this->assertSuccessfulExit($contender);
    }

    /** @return iterable<string, array{string, string, string, int, int}> */
    public static function transitionOrders(): iterable
    {
        yield 'restart then confirm' => ['restart', 'confirm', 'STALE_REVISION', 3, 2];
        yield 'confirm then restart' => ['confirm', 'restart', 'APPLIED', 4, 3];
    }

    private function seedSession(bool $confirmation = false): void
    {
        $transaction = TelegramSessionScenario::begin($this->db);
        $clock = TelegramSessionScenario::clock();
        $repository = TelegramSessionScenario::repository($this->db, $clock);
        $session = $repository->createIfAbsent($this->scenario->candidate())->versionedSession()->session();
        if ($confirmation) {
            $session->beginOrder('550e8400-e29b-41d4-a716-446655440000');
            $session->acceptComment('Synthetic confirmation draft', $clock->instant);
            $repository->save($session, 0);
        }
        $transaction->commit();
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function start(string $role, ?TelegramSessionScenario $scenario = null): array
    {
        $scenario ??= $this->scenario;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, 'tests/fixtures/telegram/session/run-session.php', $role, $scenario->profileId, $scenario->botKey],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 5),
            PlatformTestEnvironment::databaseEnvironment(),
        );
        self::assertIsResource($process);
        $child = ['process' => $process, 'pipes' => $pipes];
        $this->children[] = $child;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        self::assertTrue($this->line($child) === 'READY', 'Session process did not become ready.');

        return $child;
    }

    /** @param array{process: resource, pipes: array<int, resource>} $child */
    private function signal(array $child, string $signal): void
    {
        self::assertSame(strlen($signal) + 1, fwrite($child['pipes'][0], $signal . "\n"));
        fflush($child['pipes'][0]);
    }

    /** @param array{process: resource, pipes: array<int, resource>} $child */
    private function line(array $child): string
    {
        $deadline = hrtime(true) + 10_000_000_000;
        $line = '';
        do {
            $remaining = $deadline - hrtime(true);
            self::assertGreaterThan(0, $remaining, 'Session process barrier timed out.');
            $read = [$child['pipes'][1]];
            $write = null;
            $except = null;
            self::assertSame(1, stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1000)), 'Session process barrier timed out.');
            $chunk = fgets($child['pipes'][1], 1024 - strlen($line));
            self::assertTrue(is_string($chunk) && $chunk !== '', 'Session process ended before its receipt.');
            $line .= $chunk;
            self::assertLessThan(1023, strlen($line), 'Session receipt exceeded its limit.');
        } while (!str_ends_with($line, "\n"));

        return rtrim($line, "\r\n");
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>} $child
     * @return array{created: bool|null, outcome: string, id: string, lock_version: int, revision: int}
     */
    private function receipt(array $child): array
    {
        try {
            $receipt = json_decode($this->line($child), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail('Session receipt must be valid JSON.');
        }
        self::assertTrue(is_array($receipt) && array_keys($receipt) === ['created', 'outcome', 'id', 'lock_version', 'revision'], 'Invalid session receipt shape.');
        self::assertTrue(is_bool($receipt['created']) || $receipt['created'] === null);
        self::assertTrue(is_string($receipt['outcome']) && in_array($receipt['outcome'], ['LOADED', 'APPLIED', 'STALE_REVISION', 'ROLLED_BACK'], true));
        self::assertTrue(is_string($receipt['id']) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $receipt['id']) === 1);
        self::assertTrue(is_int($receipt['lock_version']) && $receipt['lock_version'] >= 0);
        self::assertTrue(is_int($receipt['revision']) && $receipt['revision'] >= 0);

        return $receipt;
    }

    private function assertWaiting(): void
    {
        $deadline = hrtime(true) + 1_500_000_000;
        do {
            $count = $this->db->createCommand(
                "SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'",
                [':name' => $this->scenario->botKey . '-contender'],
            )->noCache()->queryScalar();
            if ($count === 1 || $count === '1') {
                return;
            }
        } while (hrtime(true) < $deadline);
        self::fail('Competing session transaction must wait on PostgreSQL.');
    }

    /** @param array{process: resource, pipes: array<int, resource>} $child */
    private function assertSuccessfulExit(array $child): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        do {
            $status = proc_get_status($child['process']);
            if (!$status['running']) {
                self::assertSame(0, $status['exitcode']);
                self::assertTrue(stream_get_contents($child['pipes'][2]) === '', 'Session diagnostic channel must remain empty.');
                self::assertTrue(stream_get_contents($child['pipes'][1]) === '', 'Session process emitted an extra receipt.');

                return;
            }
        } while (hrtime(true) < $deadline);
        self::fail('Session process must finish within its deadline.');
    }

    private function cleanup(): void
    {
        $failure = null;
        foreach ($this->children as $child) {
            try {
                fclose($child['pipes'][0]);
                $status = proc_get_status($child['process']);
                foreach ([15, 9] as $signal) {
                    if (!$status['running']) {
                        break;
                    }
                    proc_terminate($child['process'], $signal);
                    $deadline = hrtime(true) + 3_000_000_000;
                    do {
                        $status = proc_get_status($child['process']);
                    } while ($status['running'] && hrtime(true) < $deadline);
                }
                if ($status['running']) {
                    throw new RuntimeException('session_process_cleanup_failed');
                }
                proc_close($child['process']);
            } catch (Throwable $cause) {
                $failure ??= $cause;
            } finally {
                foreach ($child['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
        }
        $this->children = [];
        if (isset($this->db)) {
            try {
                $this->db->getTransaction()?->rollBack();
                $transaction = TelegramSessionScenario::begin($this->db);
                try {
                    foreach ($this->extraScenarios as $scenario) {
                        $scenario->cleanup($this->db);
                    }
                    if (isset($this->scenario)) {
                        $this->scenario->cleanup($this->db);
                    }
                    $transaction->commit();
                } finally {
                    if ($transaction->getIsActive()) {
                        $transaction->rollBack();
                    }
                }
            } catch (Throwable $cause) {
                $failure ??= $cause;
            } finally {
                try {
                    $this->db->close();
                } catch (Throwable $cause) {
                    $failure ??= $cause;
                }
            }
        }
        if ($failure !== null) {
            throw new RuntimeException('session_cleanup_failed', 0, $failure);
        }
    }
}
