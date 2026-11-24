<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\config;

use Codeception\Test\Unit;
use modules\telegram\domain\enum\TelegramContentMode;
use modules\telegram\infrastructure\config\InvalidTelegramContentConfigException;
use modules\telegram\infrastructure\config\TelegramContentConfig;

final class TelegramContentConfigTest extends Unit
{
    /**
     * @dataProvider validModes
     */
    public function testValidMode(string $value, TelegramContentMode $expected): void
    {
        self::assertSame($expected, TelegramContentConfig::fromEnvironment([
            'TELEGRAM_CONTENT_MODE' => $value,
        ])->mode);
    }

    /** @return iterable<string, array{string, TelegramContentMode}> */
    public static function validModes(): iterable
    {
        yield 'demo' => ['demo', TelegramContentMode::DEMO];
        yield 'catalog' => ['catalog', TelegramContentMode::CATALOG];
    }

    /**
     * @param array<string, mixed> $environment
     * @dataProvider invalidModes
     */
    public function testInvalidModeHasSafeReason(array $environment): void
    {
        $this->expectException(InvalidTelegramContentConfigException::class);
        $this->expectExceptionMessageMatches('/\Atelegram_content_mode_invalid\z/');

        TelegramContentConfig::fromEnvironment($environment);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidModes(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['TELEGRAM_CONTENT_MODE' => '']];
        yield 'null' => [['TELEGRAM_CONTENT_MODE' => null]];
        yield 'wrong-type' => [['TELEGRAM_CONTENT_MODE' => 1]];
        yield 'wrong-case' => [['TELEGRAM_CONTENT_MODE' => 'DEMO']];
        yield 'padding' => [['TELEGRAM_CONTENT_MODE' => 'demo ']];
        yield 'unknown' => [['TELEGRAM_CONTENT_MODE' => 'synthetic-private-mode']];
    }
}
