<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\db;

use Codeception\Test\Unit;
use modules\telegram\infrastructure\db\TelegramSessionDbCommand;
use PDO;
use ReflectionMethod;
use Yii;
use yii\db\Connection;

final class TelegramSessionDbCommandTest extends Unit
{
    public function testSuppressesRawSqlWithoutChangingConnection(): void
    {
        $db = new Connection(['dsn' => 'pgsql:host=127.0.0.1;port=1;dbname=ideakit_test']);
        $command = new TelegramSessionDbCommand(['db' => $db, 'sql' => 'SELECT :marker']);
        $command->bindValue(':marker', 'synthetic-private-marker', PDO::PARAM_STR)->noCache();
        $messages = Yii::getLogger()->messages;
        $hook = new ReflectionMethod($command, 'logQuery');

        self::assertSame([false, null], $hook->invoke($command, 'yii\db\Command::query'));
        self::assertSame($messages, Yii::getLogger()->messages);
        self::assertSame(-1, $command->queryCacheDuration);
        self::assertTrue($db->enableLogging);
        self::assertTrue($db->enableProfiling);
        self::assertNull($db->pdo);
    }
}
