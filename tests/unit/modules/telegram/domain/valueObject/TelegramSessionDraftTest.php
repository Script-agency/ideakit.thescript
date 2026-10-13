<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\domain\valueObject;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramSessionDraft;

final class TelegramSessionDraftTest extends Unit
{
    public function testNormalizesOnlyLineEndingsAndControlCharacters(): void
    {
        $draft = TelegramSessionDraft::tryCreate("  Тест\r\n<b>два  слова</b>\r\t\0\x7f\u{0085}три\u{200D}  ", self::instant());

        self::assertNotNull($draft);
        self::assertSame("  Тест\n<b>два  слова</b>\nтри\u{200D}  ", $draft->comment());
    }

    public function testCountsUnicodeCodePointsAfterNormalization(): void
    {
        $draft = TelegramSessionDraft::tryCreate(str_repeat("я\t", 2000), self::instant());

        self::assertNotNull($draft);
        self::assertSame(str_repeat('я', 2000), $draft->comment());
        self::assertNull(TelegramSessionDraft::tryCreate(str_repeat("е\u{0301}", 1000) . 'я', self::instant()));
    }

    /**
     * @dataProvider invalidComments
     */
    public function testRejectsInvalidUserText(string $text): void
    {
        self::assertNull(TelegramSessionDraft::tryCreate($text, self::instant()));
    }

    /**
     * @dataProvider invalidComments
     */
    public function testRejectsInvalidStoredText(string $text): void
    {
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage('invalid_session_draft');

        TelegramSessionDraft::restore($text, self::instant());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidComments(): iterable
    {
        yield 'empty' => [''];
        yield 'unicode whitespace' => [" \u{00A0}\u{2003}\u{2028}\u{2029}\n"];
        yield 'controls only' => ["\0\t\x7f\u{0085}"];
        yield 'invalid utf8' => ["\xC3\x28"];
        yield 'too many code points' => [str_repeat('я', 2001)];
    }

    public function testExpiresAfterExactlyTwentyFourHoursWithMicroseconds(): void
    {
        $now = self::instant();
        $draft = TelegramSessionDraft::tryCreate('Тестовый комментарий', $now);

        self::assertNotNull($draft);
        self::assertSame('2026-10-13 10:00:00.123456', $draft->expiresAt()->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $draft->expiresAt()->getTimezone()->getName());
        self::assertSame('2026-10-12 10:00:00.123456', $now->format('Y-m-d H:i:s.u'));
        self::assertFalse($draft->isExpiredAt($draft->expiresAt()->modify('-1 microsecond')));
        self::assertTrue($draft->isExpiredAt($draft->expiresAt()));
        self::assertTrue($draft->isExpiredAt($draft->expiresAt()->modify('+1 microsecond')));
    }

    public function testRestorePreservesExpiredValueAndComparesTextAndInstant(): void
    {
        $expiry = self::instant();
        $draft = TelegramSessionDraft::restore('Тест', $expiry);

        self::assertTrue($draft->isExpiredAt($expiry->modify('+1 day')));
        self::assertSame($expiry, $draft->expiresAt());
        self::assertTrue($draft->equals(TelegramSessionDraft::restore('Тест', self::instant())));
        self::assertFalse($draft->equals(TelegramSessionDraft::restore('Другой тест', $expiry)));
        self::assertFalse($draft->equals(TelegramSessionDraft::restore('Тест', $expiry->modify('+1 microsecond'))));
    }

    /**
     * @dataProvider unnormalizedComments
     */
    public function testRestoreDoesNotRepairStoredText(string $text): void
    {
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage('invalid_session_draft');

        TelegramSessionDraft::restore($text, self::instant());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unnormalizedComments(): iterable
    {
        yield 'line endings' => ["Тест\r\n"];
        yield 'control character' => ["Тест\t"];
    }

    /**
     * @param 'create'|'restore'|'expiry' $operation
     * @dataProvider timeOperations
     */
    public function testRejectsNonUtcTime(string $operation): void
    {
        $time = self::instant()->setTimezone(new DateTimeZone('Europe/Moscow'));
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage('session_time_must_be_utc');

        match ($operation) {
            'create' => TelegramSessionDraft::tryCreate('', $time),
            'restore' => TelegramSessionDraft::restore('Тест', $time),
            'expiry' => TelegramSessionDraft::restore('Тест', self::instant())->isExpiredAt($time),
        };
    }

    /**
     * @return iterable<string, array{'create'|'restore'|'expiry'}>
     */
    public static function timeOperations(): iterable
    {
        yield 'create' => ['create'];
        yield 'restore' => ['restore'];
        yield 'expiry' => ['expiry'];
    }

    private static function instant(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-12 10:00:00.123456', new DateTimeZone('UTC'));
    }
}
