<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\db;

use Codeception\Test\Unit;
use modules\telegram\application\enum\TelegramSessionPersistenceErrorCode;
use modules\telegram\infrastructure\db\TelegramSessionDbFailure;
use PDOException;
use RuntimeException;
use yii\db\Exception;

final class TelegramSessionDbFailureTest extends Unit
{
    /** @dataProvider transientStates */
    public function testTranslatesOnlyAllowlistedTransientStates(string $state): void
    {
        $source = new Exception('synthetic-private-database-detail', [$state]);
        $failure = (new TelegramSessionDbFailure())->translate($source);

        self::assertSame(TelegramSessionPersistenceErrorCode::STORAGE_UNAVAILABLE, $failure->errorCode);
        self::assertSame('telegram_session_storage_unavailable', $failure->getMessage());
        self::assertSame($source, $failure->getPrevious());
        self::assertSame(['errorCode' => $failure->errorCode], get_object_vars($failure));
    }

    /** @return iterable<array{string}> */
    public static function transientStates(): iterable
    {
        foreach (['40001', '40P01', '55P03', '57014', '08001', '08003', '08006', '57P01', '57P02', '57P03', '53300'] as $state) {
            yield [$state];
        }
    }

    /** @dataProvider otherStates */
    public function testDoesNotInferTransientFailureFromMessageOrCode(mixed $state): void
    {
        $source = new Exception('synthetic SQLSTATE[40001]', [$state], 40001);
        $failure = (new TelegramSessionDbFailure())->translate($source);

        self::assertSame(TelegramSessionPersistenceErrorCode::STORAGE_FAILURE, $failure->errorCode);
        self::assertSame('telegram_session_storage_failure', $failure->getMessage());
        self::assertSame($source, $failure->getPrevious());
    }

    /** @return iterable<string, array{mixed}> */
    public static function otherStates(): iterable
    {
        yield 'unique violation' => ['23505'];
        yield 'invalid input' => ['22P02'];
        yield 'unlisted connection state' => ['08004'];
        yield 'unknown state' => ['ZZZZZ'];
        yield 'missing state' => [null];
        yield 'wrong type' => [40001];
        yield 'lowercase' => ['40p01'];
        yield 'padded' => [' 40001'];
    }

    public function testReadsNestedPdoMetadataAndPreservesOuterCause(): void
    {
        $pdo = new PDOException('synthetic-private-driver-detail');
        $pdo->errorInfo = ['55P03'];
        $classifier = new TelegramSessionDbFailure();
        self::assertSame(TelegramSessionPersistenceErrorCode::STORAGE_UNAVAILABLE, $classifier->translate($pdo)->errorCode);

        $outer = new Exception('synthetic-wrapper', null, 0, new RuntimeException('synthetic-local-detail', 0, $pdo));
        $failure = $classifier->translate($outer);
        self::assertSame(TelegramSessionPersistenceErrorCode::STORAGE_UNAVAILABLE, $failure->errorCode);
        self::assertSame($outer, $failure->getPrevious());

        $permanent = new Exception('synthetic-wrapper', ['23505'], 0, $pdo);
        self::assertSame(TelegramSessionPersistenceErrorCode::STORAGE_FAILURE, $classifier->translate($permanent)->errorCode);
    }

    public function testBoundsCauseTraversal(): void
    {
        $source = new Exception('synthetic-database-detail', ['40001']);
        for ($depth = 0; $depth < 7; ++$depth) {
            $source = new Exception('synthetic-wrapper', null, 0, $source);
        }
        $classifier = new TelegramSessionDbFailure();
        self::assertSame(TelegramSessionPersistenceErrorCode::STORAGE_UNAVAILABLE, $classifier->translate($source)->errorCode);
        $source = new Exception('synthetic-wrapper', null, 0, $source);
        self::assertSame(TelegramSessionPersistenceErrorCode::STORAGE_FAILURE, $classifier->translate($source)->errorCode);
    }
}
