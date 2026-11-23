<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\domain\valueObject;

use Codeception\Test\Unit;
use modules\telegram\domain\enum\TelegramSessionFlow;
use modules\telegram\domain\enum\TelegramSessionTransitionOutcome;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;
use modules\telegram\domain\valueObject\TelegramSessionTransitionResult;

final class TelegramSessionTransitionResultTest extends Unit
{
    /**
     * @dataProvider outcomes
     */
    public function testRequiresSaveOnlyForAppliedChanges(string $outcome, bool $requiresSave): void
    {
        $revision = new TelegramInteractionRevision(2);
        $result = new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::from($outcome),
            TelegramSessionFlow::BROWSING,
            $revision,
        );
        $revision->next();

        self::assertSame($requiresSave, $result->requiresSave());
        self::assertSame(2, $result->interactionRevision->value());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'applied' => ['APPLIED', true];
        yield 'expired cleanup' => ['DRAFT_EXPIRED', true];
        yield 'no change' => ['NO_CHANGE', false];
        yield 'invalid state' => ['INVALID_STATE', false];
        yield 'stale revision' => ['STALE_REVISION', false];
        yield 'invalid comment' => ['INVALID_COMMENT', false];
        yield 'unsupported flow' => ['UNSUPPORTED_FLOW', false];
        yield 'unsupported mode' => ['UNSUPPORTED_MODE', false];
    }

    public function testEqualityIncludesOutcomeFlowAndRevision(): void
    {
        $revision = new TelegramInteractionRevision(1);
        $result = new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::APPLIED,
            TelegramSessionFlow::BROWSING,
            $revision,
        );

        self::assertTrue($result->equals(new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::APPLIED,
            TelegramSessionFlow::BROWSING,
            new TelegramInteractionRevision(1),
        )));
        self::assertFalse($result->equals(new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::DRAFT_EXPIRED,
            TelegramSessionFlow::BROWSING,
            $revision,
        )));
        self::assertFalse($result->equals(new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::APPLIED,
            TelegramSessionFlow::AWAITING_LEAD_COMMENT,
            $revision,
        )));
        self::assertFalse($result->equals(new TelegramSessionTransitionResult(
            TelegramSessionTransitionOutcome::APPLIED,
            TelegramSessionFlow::BROWSING,
            $revision->next(),
        )));
    }
}
