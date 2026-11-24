<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\presentation\keyboard;

use Codeception\Test\Unit;
use InvalidArgumentException;
use modules\telegram\application\dto\IdeaCardView;
use modules\telegram\application\dto\IdeaDetailsView;
use modules\telegram\application\port\ITelegramCallbackEncoder;
use modules\telegram\infrastructure\transport\callback\CallbackAction;
use modules\telegram\infrastructure\transport\callback\CallbackSigningKeys;
use modules\telegram\infrastructure\transport\callback\CodecTelegramCallbackEncoder;
use modules\telegram\infrastructure\transport\callback\InvalidCallbackDataException;
use modules\telegram\infrastructure\transport\callback\TelegramCallbackCodec;
use modules\telegram\presentation\keyboard\TelegramInlineKeyboard;
use modules\telegram\presentation\keyboard\TelegramKeyboardFactory;

final class TelegramKeyboardFactoryTest extends Unit
{
    private const CARD_ID = '019d0000-0000-7000-8000-000000000002';
    private const DETAILS_CARD_ID = '019d0000-0000-7000-8000-000000000003';
    private const PROFILE_ID = '019d0000-0000-7000-8000-000000000101';
    private const OTHER_PROFILE_ID = '019d0000-0000-7000-8000-000000000102';
    private const BOT_KEY = 'demo-a';

    public function testReplyLayoutsDoNotCallEncoder(): void
    {
        $encoder = $this->createMock(ITelegramCallbackEncoder::class);
        foreach (['details', 'order', 'pdf', 'confirmOrder', 'cancelOrder'] as $method) {
            $encoder->expects(self::never())->method($method);
        }
        $factory = new TelegramKeyboardFactory($encoder);

        foreach ([
            [$factory->browsing(), 'Следующая идея'],
            [$factory->commentCancellation(), 'Отменить'],
        ] as [$keyboard, $text]) {
            self::assertSame([
                'keyboard' => [[['text' => $text]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => false,
                'is_persistent' => true,
            ], $keyboard->toArray());
        }
    }

    public function testCardButtonsUseTheSuppliedCard(): void
    {
        $codec = $this->codec();
        $factory = new TelegramKeyboardFactory(new CodecTelegramCallbackEncoder($codec));

        $this->assertInline(
            $factory->forCard($this->card(), self::BOT_KEY, self::PROFILE_ID),
            [['Детали', CallbackAction::DETAILS], ['Заказать у нас', CallbackAction::ORDER]],
            self::CARD_ID,
            $codec,
        );
    }

    public function testDetailsButtonsUseTheEmbeddedCard(): void
    {
        $codec = $this->codec();
        $factory = new TelegramKeyboardFactory(new CodecTelegramCallbackEncoder($codec));
        $details = new IdeaDetailsView(
            new IdeaCardView(
                self::DETAILS_CARD_ID,
                'Тестовая карточка 3',
                'Тестовая проблема',
                'Тестовая аудитория',
                'Тестовая модель',
                'Тестовое описание',
            ),
            'Тестовый раздел',
            'Тестовый раздел',
            'Тестовый раздел',
            'Тестовый раздел',
            'Тестовый раздел',
            'Тестовый раздел',
        );

        $this->assertInline(
            $factory->forDetails($details, self::BOT_KEY, self::PROFILE_ID),
            [['Получить PDF', CallbackAction::PDF], ['Заказать у нас', CallbackAction::ORDER]],
            self::DETAILS_CARD_ID,
            $codec,
        );
    }

    /**
     * @dataProvider confirmationRevisions
     */
    public function testConfirmationButtonsUseTheSuppliedRevision(int $revision): void
    {
        $codec = $this->codec();
        $factory = new TelegramKeyboardFactory(new CodecTelegramCallbackEncoder($codec));

        $this->assertInline(
            $factory->forConfirmation($revision, self::BOT_KEY, self::PROFILE_ID),
            [['Подтвердить', CallbackAction::CONFIRM_ORDER], ['Отменить', CallbackAction::CANCEL_ORDER]],
            $revision,
            $codec,
        );
    }

    /** @return iterable<string, array{int}> */
    public static function confirmationRevisions(): iterable
    {
        yield 'zero' => [0];
        yield 'nonzero' => [37];
    }

    public function testConsecutiveCallsKeepTheirOwnContext(): void
    {
        $codec = $this->codec();
        $factory = new TelegramKeyboardFactory(new CodecTelegramCallbackEncoder($codec));
        $callbacks = [];

        foreach ([
            [self::BOT_KEY, self::PROFILE_ID],
            ['demo-b', self::PROFILE_ID],
            ['demo-b', self::OTHER_PROFILE_ID],
            [self::BOT_KEY, self::PROFILE_ID],
        ] as [$botKey, $profileId]) {
            $keyboard = $factory->forCard($this->card(), $botKey, $profileId);
            $this->assertInline(
                $keyboard,
                [['Детали', CallbackAction::DETAILS], ['Заказать у нас', CallbackAction::ORDER]],
                self::CARD_ID,
                $codec,
                $botKey,
                $profileId,
            );
            $callbacks[] = $keyboard->toArray()['inline_keyboard'][0][0]['callback_data'];
        }

        self::assertTrue($callbacks[0] === $callbacks[3]);

        foreach ([
            ['demo-b', self::PROFILE_ID],
            [self::BOT_KEY, self::OTHER_PROFILE_ID],
        ] as [$botKey, $profileId]) {
            try {
                $codec->decode($callbacks[0], $botKey, $profileId);
                self::fail('callback_context_was_not_bound');
            } catch (InvalidCallbackDataException $failure) {
                self::assertSame('invalid_callback_data', $failure->getMessage());
            }
        }
    }

    public function testInvalidContextKeepsTheExistingSafeFailure(): void
    {
        $factory = new TelegramKeyboardFactory(new CodecTelegramCallbackEncoder($this->codec()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_callback_context');
        $factory->forCard($this->card(), '', self::PROFILE_ID);
    }

    public function testSecondButtonFailureDoesNotReturnAPartialKeyboard(): void
    {
        $codec = $this->codec();
        $failure = new InvalidArgumentException('invalid_callback_context');
        $encoder = $this->createMock(ITelegramCallbackEncoder::class);
        $encoder->expects(self::once())
            ->method('details')
            ->with(self::CARD_ID, self::BOT_KEY, self::PROFILE_ID)
            ->willReturn($codec->encodeCard(
                CallbackAction::DETAILS,
                self::CARD_ID,
                self::BOT_KEY,
                self::PROFILE_ID,
            ));
        $encoder->expects(self::once())
            ->method('order')
            ->with(self::CARD_ID, self::BOT_KEY, self::PROFILE_ID)
            ->willThrowException($failure);
        $factory = new TelegramKeyboardFactory($encoder);

        try {
            $factory->forCard($this->card(), self::BOT_KEY, self::PROFILE_ID);
            self::fail('partial_keyboard_was_returned');
        } catch (InvalidArgumentException $caught) {
            self::assertTrue($caught === $failure);
        }
    }

    private function codec(): TelegramCallbackCodec
    {
        return new TelegramCallbackCodec(
            CallbackSigningKeys::fromBase64(base64_encode(str_repeat('K', 32))),
        );
    }

    private function card(): IdeaCardView
    {
        return new IdeaCardView(
            self::CARD_ID,
            'Тестовая карточка 2',
            'Тестовая проблема',
            'Тестовая аудитория',
            'Тестовая модель',
            'Тестовое описание',
        );
    }

    /**
     * @param array{array{string, CallbackAction}, array{string, CallbackAction}} $expectedButtons
     */
    private function assertInline(
        TelegramInlineKeyboard $keyboard,
        array $expectedButtons,
        string|int $expectedSubject,
        TelegramCallbackCodec $codec,
        string $botKey = self::BOT_KEY,
        string $profileId = self::PROFILE_ID,
    ): void {
        $markup = $keyboard->toArray();
        self::assertSame(['inline_keyboard'], array_keys($markup));
        self::assertSame([0], array_keys($markup['inline_keyboard']));
        self::assertSame([0, 1], array_keys($markup['inline_keyboard'][0]));

        foreach ($expectedButtons as $index => [$text, $action]) {
            $button = $markup['inline_keyboard'][0][$index];
            self::assertSame(['text', 'callback_data'], array_keys($button));
            self::assertSame($text, $button['text']);
            self::assertGreaterThanOrEqual(1, strlen($button['callback_data']));
            self::assertLessThanOrEqual(64, strlen($button['callback_data']));
            $verified = $codec->decode($button['callback_data'], $botKey, $profileId);
            self::assertSame($action, $verified->action);
            self::assertSame($expectedSubject, $verified->subject);
        }
    }
}
