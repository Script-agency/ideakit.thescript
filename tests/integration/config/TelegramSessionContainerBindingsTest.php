<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use modules\telegram\application\port\ITelegramBotSessionRepository;
use modules\telegram\infrastructure\db\DbTelegramBotSessionRepository;
use Symfony\Component\Process\Process;
use tests\fixtures\platform\PlatformTestEnvironment;
use tests\fixtures\telegram\session\TelegramSessionScenario;
use Yii;
use yii\di\Container;

final class TelegramSessionContainerBindingsTest extends Unit
{
    /** @dataProvider configurations */
    public function testRepositoryUsesCurrentApplicationDatabase(string $configuration): void
    {
        $originalContainer = Yii::$container;
        $originalDb = Yii::$app->get('db');
        $db = TelegramSessionScenario::connection();
        $replacement = TelegramSessionScenario::connection();
        Yii::$container = new Container();
        Yii::$app->set('db', $db);
        try {
            (static fn (): array => require dirname(__DIR__, 3) . '/config/' . $configuration . '.php')();
            self::assertTrue(Yii::$container->has(ITelegramBotSessionRepository::class));
            $first = Yii::$container->get(ITelegramBotSessionRepository::class);
            self::assertInstanceOf(DbTelegramBotSessionRepository::class, $first);
            Yii::$app->set('db', $replacement);
            $repository = Yii::$container->get(ITelegramBotSessionRepository::class);
            self::assertInstanceOf(DbTelegramBotSessionRepository::class, $repository);
            self::assertNotSame($first, $repository);
            $transaction = TelegramSessionScenario::begin($replacement);
            $scenario = TelegramSessionScenario::seed($replacement);
            self::assertTrue($repository->createIfAbsent($scenario->candidate())->created());
            self::assertSame($transaction, $replacement->getTransaction());
            self::assertNull($db->getTransaction());
            $transaction->rollBack();
            self::assertFalse($db->createCommand(
                'SELECT id FROM {{%telegram_bot_sessions}} WHERE bot_key = :bot',
                [':bot' => $scenario->botKey],
            )->queryScalar());
        } finally {
            try {
                try {
                    $replacement->getTransaction()?->rollBack();
                } finally {
                    $replacement->close();
                }
            } finally {
                try {
                    $db->close();
                } finally {
                    Yii::$app->set('db', $originalDb);
                    Yii::$container = $originalContainer;
                }
            }
        }
    }

    /** @dataProvider configurations */
    public function testBootstrapAndResolutionWithoutNetworkOrWebhookSettings(string $configuration): void
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/telegram/session/bootstrap.php', $configuration],
            dirname(__DIR__, 3),
            PlatformTestEnvironment::applicationEnvironment(),
        );
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), 'Session bootstrap probe failed.');
        self::assertTrue($process->getErrorOutput() === '', 'Session bootstrap emitted unexpected diagnostics.');
        self::assertTrue($process->getOutput() === 'session_bootstrap_ok', 'Unexpected session bootstrap output.');
    }

    /** @return iterable<string, array{string}> */
    public static function configurations(): iterable
    {
        yield 'web' => ['web'];
        yield 'console' => ['console'];
    }
}
