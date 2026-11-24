<?php

declare(strict_types=1);

namespace tests\integration\config;

use Codeception\Test\Unit;
use Symfony\Component\Process\Process;
use tests\fixtures\platform\PlatformTestEnvironment;

final class TelegramContentContainerBindingsTest extends Unit
{
    /** @dataProvider basicConfigurations */
    public function testBootstrapDoesNotRequireValidSigningKeys(string $application, bool $invalidKey): void
    {
        $this->probe($application, 'basic', [
            'TELEGRAM_CALLBACK_CURRENT_KEY' => $invalidKey ? '!' : false,
        ]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function basicConfigurations(): iterable
    {
        yield 'web-no-keys' => ['web', false];
        yield 'console-no-keys' => ['console', false];
        yield 'web-unused-invalid-key' => ['web', true];
    }

    /** @dataProvider applications */
    public function testSigningGraphUsesCurrentAndPreviousKeys(string $application): void
    {
        $this->probe($application, 'signed', [
            'TELEGRAM_CALLBACK_CURRENT_KEY' => self::currentKey(),
            'TELEGRAM_CALLBACK_PREVIOUS_KEY' => self::previousKey(),
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function applications(): iterable
    {
        yield 'web' => ['web'];
        yield 'console' => ['console'];
    }

    public function testEmptyPreviousKeyIsOptional(): void
    {
        $this->probe('console', 'signed', [
            'TELEGRAM_CALLBACK_CURRENT_KEY' => self::currentKey(),
            'TELEGRAM_CALLBACK_PREVIOUS_KEY' => '',
        ]);
    }

    /** @dataProvider rejectedModes */
    public function testModeIsRejectedDuringConfiguration(
        string $application,
        string $scenario,
        string|false $mode,
        string $reason,
    ): void {
        $this->probe($application, $scenario, ['TELEGRAM_CONTENT_MODE' => $mode], $reason);
    }

    /** @return iterable<string, array{string, string, string|false, string}> */
    public static function rejectedModes(): iterable
    {
        yield 'unknown' => ['web', 'basic', 'unknown', 'telegram_content_mode_invalid'];
        yield 'catalog' => ['console', 'basic', 'catalog', 'telegram_catalog_unavailable'];
        yield 'missing' => ['web', 'basic', false, 'telegram_content_mode_invalid'];
        yield 'env-null-over-process-demo' => ['web', 'null-mode', 'demo', 'telegram_content_mode_invalid'];
    }

    /**
     * @param array<string, string|false> $environment
     * @dataProvider rejectedKeys
     */
    public function testKeyFailureOccursOnlyWhenSigningIsRequested(
        string $application,
        string $scenario,
        array $environment,
    ): void {
        $this->probe($application, $scenario, $environment, 'invalid_callback_key_config');
    }

    /** @return iterable<string, array{string, string, array<string, string|false>}> */
    public static function rejectedKeys(): iterable
    {
        yield 'missing-current' => ['web', 'signed', []];
        yield 'invalid-previous' => ['console', 'signed', [
            'TELEGRAM_CALLBACK_CURRENT_KEY' => self::currentKey(),
            'TELEGRAM_CALLBACK_PREVIOUS_KEY' => '!',
        ]];
        yield 'env-null-over-process-current' => ['web', 'null-current', [
            'TELEGRAM_CALLBACK_CURRENT_KEY' => self::currentKey(),
            'TELEGRAM_CALLBACK_PREVIOUS_KEY' => self::previousKey(),
        ]];
    }

    /** @param array<string, string|false> $environment */
    private function probe(
        string $application,
        string $scenario,
        array $environment = [],
        ?string $reason = null,
    ): void {
        $environment = array_replace(PlatformTestEnvironment::applicationEnvironment(), [
            'TELEGRAM_CONTENT_MODE' => 'demo',
            'TELEGRAM_CALLBACK_CURRENT_KEY' => false,
            'TELEGRAM_CALLBACK_PREVIOUS_KEY' => false,
            'TELEGRAM_BOT_KEY' => false,
            'TELEGRAM_WEBHOOK_SECRET' => false,
        ], $environment);
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/telegram/content/bootstrap.php', $application, $scenario],
            dirname(__DIR__, 3),
            $environment,
        );
        $process->setTimeout(10);
        $process->run();

        self::assertSame($reason === null ? 0 : 1, $process->getExitCode(), 'content_probe_exit');
        self::assertTrue(
            $process->getOutput() === ($reason === null ? "content_probe_ok\n" : ''),
            'unexpected_content_probe_stdout',
        );
        self::assertTrue(
            $process->getErrorOutput() === ($reason === null ? '' : $reason . "\n"),
            'unexpected_content_probe_stderr',
        );
    }

    private static function currentKey(): string
    {
        return base64_encode(str_repeat('K', 32));
    }

    private static function previousKey(): string
    {
        return base64_encode(str_repeat('L', 32));
    }
}
