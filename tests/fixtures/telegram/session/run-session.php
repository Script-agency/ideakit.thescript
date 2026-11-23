<?php

declare(strict_types=1);

use modules\telegram\application\exception\TelegramSessionPersistenceException;
use modules\telegram\domain\entity\TelegramBotSession;
use modules\telegram\domain\valueObject\TelegramBotSessionId;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use Ramsey\Uuid\Uuid;
use tests\fixtures\telegram\session\TelegramSessionScenario;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

$barrier = static function (): string {
    stream_set_blocking(STDIN, false);
    $deadline = hrtime(true) + 10_000_000_000;
    $line = '';
    do {
        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('session_barrier_timeout');
        }
        $read = [STDIN];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1000)) !== 1) {
            throw new RuntimeException('session_barrier_timeout');
        }
        $chunk = fgets(STDIN, 64 - strlen($line));
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('session_barrier_closed');
        }
        $line .= $chunk;
        if (strlen($line) >= 63) {
            throw new RuntimeException('invalid_session_signal');
        }
    } while (!str_ends_with($line, "\n"));

    return rtrim($line, "\r\n");
};

$exitCode = 0;
$db = null;
try {
    $role = $argv[1] ?? '';
    $profile = $argv[2] ?? '';
    $bot = $argv[3] ?? '';
    if (!in_array($role, ['holder-create', 'contender-create', 'holder-restart', 'holder-confirm',
        'contender-restart', 'contender-confirm', 'contender-load'], true)
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $profile) !== 1
        || preg_match('/\Asession-test-[a-f0-9-]{36}\z/', $bot) !== 1
    ) {
        throw new RuntimeException('invalid_session_scenario');
    }
    $db = TelegramSessionScenario::connection();
    $holder = str_starts_with($role, 'holder-');
    $db->createCommand(
        "SELECT set_config('application_name', :name, false)",
        [':name' => $bot . ($holder ? '-holder' : '-contender')],
    )->queryScalar();
    $clock = TelegramSessionScenario::clock();
    $repository = TelegramSessionScenario::repository($db, $clock);
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if ($barrier() !== 'GO') {
        throw new RuntimeException('invalid_session_signal');
    }
    $transaction = TelegramSessionScenario::begin($db);
    $created = null;
    $outcome = 'LOADED';
    if (str_ends_with($role, '-create')) {
        $result = $repository->createIfAbsent(TelegramBotSession::createDemo(
            new TelegramBotSessionId(Uuid::uuid7()->toString()),
            $profile,
            $bot,
            91,
            null,
        ));
        $loaded = $result->versionedSession();
        $created = $result->created();
    } else {
        $loaded = $repository->loadForUpdate($bot, 91, $profile);
        if ($loaded === null) {
            throw new RuntimeException('session_fixture_missing');
        }
        $transition = match (true) {
            str_ends_with($role, '-restart') => $loaded->session()->restartBrowsing(null),
            str_ends_with($role, '-confirm') => $loaded->session()->confirmOrder(new TelegramInteractionRevision(2), $clock->instant, null),
            default => null,
        };
        if ($transition !== null) {
            $outcome = $transition->outcome->value;
            if ($transition->requiresSave()) {
                $loaded = $repository->save($loaded->session(), $loaded->lockVersion());
            }
        }
    }
    if ($holder) {
        fwrite(STDOUT, "LOCKED\n");
        fflush(STDOUT);
        $decision = $barrier();
        if ($decision === 'ROLLBACK') {
            $transaction->rollBack();
            $outcome = 'ROLLED_BACK';
        } elseif ($decision === 'COMMIT') {
            $transaction->commit();
        } else {
            throw new RuntimeException('invalid_session_signal');
        }
    } else {
        $transaction->commit();
    }
    fwrite(STDOUT, json_encode([
        'created' => $created, 'outcome' => $outcome, 'id' => $loaded->session()->getId()->value(),
        'lock_version' => $loaded->lockVersion(), 'revision' => $loaded->session()->getInteractionRevision()->value(),
    ], JSON_THROW_ON_ERROR) . "\n");
} catch (TelegramSessionPersistenceException $failure) {
    fwrite(STDOUT, 'ERROR:' . $failure->errorCode->value . "\n");
} catch (Throwable) {
    fwrite(STDERR, "session_scenario_failed\n");
    $exitCode = 1;
} finally {
    if ($db !== null) {
        try {
            $db->getTransaction()?->rollBack();
        } catch (Throwable) {
            fwrite(STDERR, "session_cleanup_failed\n");
            $exitCode = 1;
        } finally {
            try {
                $db->close();
            } catch (Throwable) {
                fwrite(STDERR, "session_cleanup_failed\n");
                $exitCode = 1;
            }
        }
    }
}
exit($exitCode);
