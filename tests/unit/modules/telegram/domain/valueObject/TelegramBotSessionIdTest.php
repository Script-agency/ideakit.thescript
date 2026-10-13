<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\domain\valueObject;

use Codeception\Test\Unit;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramBotSessionId;

final class TelegramBotSessionIdTest extends Unit
{
    private const UUID = '01890f4d-3c2a-7f48-8c0b-123456789abc';

    public function testCanonicalizesUuidV7AndComparesByValue(): void
    {
        $id = new TelegramBotSessionId(strtoupper(self::UUID));

        self::assertSame(self::UUID, $id->value());
        self::assertTrue($id->equals(new TelegramBotSessionId(self::UUID)));
        self::assertFalse($id->equals(new TelegramBotSessionId('01890f4d-3c2a-7f48-8c0b-123456789abd')));
    }

    /**
     * @dataProvider invalidIdentifiers
     */
    public function testRejectsInvalidIdentifierWithSafeReason(string $value): void
    {
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage('invalid_telegram_bot_session_id');

        new TelegramBotSessionId($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['invalid-session'];
        yield 'wrong version' => ['01890f4d-3c2a-4f48-8c0b-123456789abc'];
        yield 'wrong variant' => ['01890f4d-3c2a-7f48-7c0b-123456789abc'];
        yield 'leading space' => [' ' . self::UUID];
        yield 'trailing newline' => [self::UUID . "\n"];
    }
}
