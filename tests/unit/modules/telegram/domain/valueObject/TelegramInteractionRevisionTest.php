<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\domain\valueObject;

use Codeception\Test\Unit;
use modules\telegram\domain\exception\TelegramSessionStateViolationException;
use modules\telegram\domain\valueObject\TelegramInteractionRevision;

final class TelegramInteractionRevisionTest extends Unit
{
    public function testIncrementCreatesANewValueWithoutChangingTheOriginal(): void
    {
        $revision = new TelegramInteractionRevision(0);
        $next = $revision->next();

        self::assertSame(0, $revision->value());
        self::assertSame(1, $next->value());
        self::assertTrue($next->equals(new TelegramInteractionRevision(1)));
        self::assertFalse($next->equals($revision));
        self::assertSame(PHP_INT_MAX, (new TelegramInteractionRevision(PHP_INT_MAX - 1))->next()->value());
    }

    public function testRejectsNegativeRevision(): void
    {
        $this->expectException(TelegramSessionStateViolationException::class);
        $this->expectExceptionMessage('invalid_interaction_revision');

        new TelegramInteractionRevision(-1);
    }

    public function testRejectsOverflowWithoutChangingRevision(): void
    {
        $revision = new TelegramInteractionRevision(PHP_INT_MAX);
        try {
            $revision->next();
            self::fail('Expected revision overflow rejection.');
        } catch (TelegramSessionStateViolationException $exception) {
            self::assertSame('interaction_revision_overflow', $exception->getMessage());
            self::assertSame(PHP_INT_MAX, $revision->value());
        }
    }
}
