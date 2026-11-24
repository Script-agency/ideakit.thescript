<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\presentation\message;

use Codeception\Test\Unit;
use modules\telegram\application\dto\IdeaCardView;
use modules\telegram\application\dto\IdeaDetailsView;
use modules\telegram\infrastructure\content\ConfiguredDemoIdeaProvider;
use modules\telegram\presentation\enum\TelegramMessageRenderingErrorCode;
use modules\telegram\presentation\exception\TelegramMessageRenderingException;
use modules\telegram\presentation\message\RenderedTelegramText;
use modules\telegram\presentation\message\TelegramMessageRenderer;

final class TelegramMessageRendererTest extends Unit
{
    public function testCardPreservesFieldOrderAndEscapesEveryValue(): void
    {
        $card = new IdeaCardView(
            '019d0000-0000-7000-8000-000000000001',
            '<b>Название</b> & "цитата"',
            "Проблема < > '",
            'Аудитория &lt;b&gt;',
            'Модель &amp; &#039;',
            "  Кратко 😀\nСледующая строка  ",
        );

        $result = (new TelegramMessageRenderer())->card($card);

        self::assertSame(
            "<b>&lt;b&gt;Название&lt;/b&gt; &amp; &quot;цитата&quot;</b>\n\n"
            . "  Кратко 😀\nСледующая строка  \n\n"
            . "<b>Проблема:</b>\nПроблема &lt; &gt; &#039;\n\n"
            . "<b>Аудитория:</b>\nАудитория &amp;lt;b&amp;gt;\n\n"
            . "<b>Монетизация:</b>\nМодель &amp;amp; &amp;#039;",
            $result->text(),
        );
        self::assertSame('HTML', $result->parseMode());
        self::assertStringNotContainsString($card->id(), $result->text());
    }

    public function testDetailsRenderOnlyTheSuppliedTitleAndSixSections(): void
    {
        $card = new IdeaCardView('synthetic-id', '<i>Другая идея</i>', 'unused', 'unused', 'unused', 'unused');
        $details = new IdeaDetailsView(
            $card,
            'Проблема & аудитория',
            'Бизнес <модель>',
            'Рынок "конкуренты"',
            "Риски 'допущения'",
            'MVP &lt;этап&gt;',
            "  День 😀\nДень 2  ",
        );

        $result = (new TelegramMessageRenderer())->details($details);

        self::assertSame(
            "<b>&lt;i&gt;Другая идея&lt;/i&gt;</b>\n\n"
            . "<b>Проблема и аудитория</b>\nПроблема &amp; аудитория\n\n"
            . "<b>Бизнес-модель</b>\nБизнес &lt;модель&gt;\n\n"
            . "<b>Рынок и конкуренты</b>\nРынок &quot;конкуренты&quot;\n\n"
            . "<b>Риски и предположения</b>\nРиски &#039;допущения&#039;\n\n"
            . "<b>План минимальной версии продукта</b>\nMVP &amp;lt;этап&amp;gt;\n\n"
            . "<b>Первые 30 дней</b>\n  День 😀\nДень 2  ",
            $result->text(),
        );
        self::assertSame('HTML', $result->parseMode());
        self::assertStringNotContainsString('unused', $result->text());
        self::assertStringNotContainsString($card->id(), $result->text());
    }

    public function testExistingDemoCardsAndDetailsCanBeRendered(): void
    {
        $provider = new ConfiguredDemoIdeaProvider();
        $renderer = new TelegramMessageRenderer();

        foreach ([
            '019d0000-0000-7000-8000-000000000001',
            '019d0000-0000-7000-8000-000000000002',
            '019d0000-0000-7000-8000-000000000003',
        ] as $id) {
            $card = $provider->findCard($id);
            $details = $provider->findDetails($id);
            self::assertNotNull($card);
            self::assertNotNull($details);
            foreach ([$renderer->card($card), $renderer->details($details)] as $result) {
                self::assertStringStartsWith('<b>' . $card->title() . "</b>\n\n", $result->text());
                self::assertStringNotContainsString($id, $result->text());
                self::assertSame('HTML', $result->parseMode());
            }
        }
    }

    /** @dataProvider messageSizeCases */
    public function testVisibleSizeIncludesTemplateAndCountsUtf16Units(string $kind, int $excess): void
    {
        $units = 4096 + $excess - ($kind === 'card' ? 46 : 142);
        $text = str_repeat('😀', intdiv($units, 2)) . ($units % 2 === 1 ? 'Я' : '');
        $renderer = new TelegramMessageRenderer();
        $render = fn (): RenderedTelegramText => $kind === 'card'
            ? $renderer->card($this->ideaCard($text))
            : $renderer->details($this->ideaDetails($text));

        if ($excess !== 0) {
            $this->assertRenderingFailure($render, TelegramMessageRenderingErrorCode::TEXT_TOO_LONG);
            return;
        }

        $result = $render();
        self::assertStringContainsString($text, $result->text());
        self::assertSame('HTML', $result->parseMode());
    }

    /** @return iterable<string, array{string, int}> */
    public static function messageSizeCases(): iterable
    {
        yield 'card 4096' => ['card', 0];
        yield 'card 4097' => ['card', 1];
        yield 'details 4096' => ['details', 0];
        yield 'details 4097' => ['details', 1];
    }

    /** @dataProvider invalidTextCases */
    public function testInvalidUtf8IsSafeAndTakesPriorityOverSize(string $kind, bool $oversized): void
    {
        $text = $oversized ? str_repeat('Я', 5000) : 'Синтетический текст';
        $renderer = new TelegramMessageRenderer();

        $this->assertRenderingFailure(
            fn (): RenderedTelegramText => $kind === 'card'
                ? $renderer->card($this->ideaCard($text, 'З', "закрытый текст\xC3"))
                : $renderer->details($this->ideaDetails($text, "закрытый текст\xFF")),
            TelegramMessageRenderingErrorCode::INVALID_TEXT,
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function invalidTextCases(): iterable
    {
        yield 'card invalid' => ['card', false];
        yield 'card invalid after oversized field' => ['card', true];
        yield 'details invalid after oversized field' => ['details', true];
    }

    public function testFixedDialogueMessagesMatchTheAgreedCopy(): void
    {
        $renderer = new TelegramMessageRenderer();
        $messages = [
            [
                $renderer->demoGreeting(),
                "<b>Добро пожаловать в IDEAKIT!</b>\n\n"
                . "Сейчас доступен демонстрационный режим: карточки тестовые, заявки не отправляются.\n\n"
                . 'Нажимайте «Следующая идея» для просмотра карточек. У карточки доступны «Детали» и «Заказать у нас».',
            ],
            [
                $renderer->demoCommentPrompt(),
                "<b>Демонстрационное оформление</b>\n\n"
                . "Введите комментарий к карточке — до 2000 символов.\n\n"
                . 'Заявка не будет отправлена. Для отмены нажмите «Отменить».',
            ],
            [$renderer->demoOrderCompleted(), 'Демонстрация завершена. Заявка не отправлена.'],
            [$renderer->orderCancelled(), 'Оформление отменено.'],
            [
                $renderer->draftExpired(),
                'Время хранения комментария истекло. Нажмите «Заказать у нас», чтобы начать заново.',
            ],
            [$renderer->browseHint(), 'Используйте кнопку «Следующая идея» или кнопки под карточкой.'],
            [$renderer->finishOrderHint(), 'Завершите текущее оформление или нажмите «Отменить».'],
            [$renderer->invalidComment(), 'Введите непустой комментарий длиной до 2000 символов.'],
            [$renderer->cardUnavailable(), 'Карточка недоступна. Нажмите «Следующая идея», чтобы продолжить просмотр.'],
            [$renderer->demoPdfUnavailable(), 'PDF будет доступен после запуска каталога.'],
        ];

        foreach ($messages as [$result, $expected]) {
            self::assertSame($expected, $result->text());
            self::assertSame('HTML', $result->parseMode());
        }
    }

    /** @dataProvider commentCases */
    public function testCommentRemainsCompleteWithWhitespaceUnicodeAndLiteralEntities(string $comment, string $escaped): void
    {
        $result = (new TelegramMessageRenderer())->demoCommentConfirmation($comment);

        self::assertSame(
            "<b>Проверьте комментарий:</b>\n\n" . $escaped
            . "\n\nЭто демонстрация: заявка не будет отправлена.",
            $result->text(),
        );
        self::assertSame('HTML', $result->parseMode());
        if ($comment === str_repeat('&', 2000)) {
            self::assertGreaterThan(4096, strlen($result->text()));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function commentCases(): iterable
    {
        yield '2000 non BMP characters fit with 71 fixed units' => [str_repeat('😀', 2000), str_repeat('😀', 2000)];
        yield '2000 ampersands are measured before escaping' => [str_repeat('&', 2000), str_repeat('&amp;', 2000)];
        yield 'whitespace and literal entities' => [
            "  Комментарий 😀\n\t<b>текст</b> &lt;b&gt; &amp; &#039; ' \"  \n ",
            "  Комментарий 😀\n\t&lt;b&gt;текст&lt;/b&gt; &amp;lt;b&amp;gt; &amp;amp; &amp;#039; &#039; &quot;  \n ",
        ];
    }

    public function testCommentUsesTheSameSafeTechnicalFailures(): void
    {
        $renderer = new TelegramMessageRenderer();
        $this->assertRenderingFailure(
            fn (): RenderedTelegramText => $renderer->demoCommentConfirmation(str_repeat('😀', 2013)),
            TelegramMessageRenderingErrorCode::TEXT_TOO_LONG,
        );
        $this->assertRenderingFailure(
            fn (): RenderedTelegramText => $renderer->demoCommentConfirmation(str_repeat('😀', 2013) . "\xC3"),
            TelegramMessageRenderingErrorCode::INVALID_TEXT,
        );
    }

    public function testInterleavedCallsDoNotChangeResultsOrInputs(): void
    {
        $renderer = new TelegramMessageRenderer();
        $card = $this->ideaCard('Краткое описание');
        $cardSnapshot = clone $card;
        $first = $renderer->card($card);
        $firstText = $first->text();
        $comment = "  Первый комментарий 😀\n ";
        $confirmation = $renderer->demoCommentConfirmation($comment);
        $confirmationText = $confirmation->text();

        $renderer->card($this->ideaCard('Другая карточка', 'Другой заголовок'));
        $renderer->details($this->ideaDetails('Подробности другой карточки'));
        $renderer->demoCommentConfirmation('Другой комментарий');
        $renderer->demoGreeting();
        $this->assertRenderingFailure(
            fn (): RenderedTelegramText => $renderer->card($this->ideaCard("\xFF")),
            TelegramMessageRenderingErrorCode::INVALID_TEXT,
        );

        self::assertSame($firstText, $first->text());
        self::assertSame($firstText, $renderer->card($card)->text());
        self::assertSame($confirmationText, $confirmation->text());
        self::assertSame($confirmationText, $renderer->demoCommentConfirmation($comment)->text());
        self::assertSame($firstText, (new TelegramMessageRenderer())->card($card)->text());
        self::assertEquals($cardSnapshot, $card);
        self::assertSame("  Первый комментарий 😀\n ", $comment);
    }

    private function ideaCard(string $summary, string $title = 'З', string $monetization = 'М'): IdeaCardView
    {
        return new IdeaCardView('synthetic-id', $title, 'П', 'А', $monetization, $summary);
    }

    private function ideaDetails(string $problem, string $last = 'Д'): IdeaDetailsView
    {
        return new IdeaDetailsView($this->ideaCard('unused'), $problem, 'Б', 'Р', 'И', 'М', $last);
    }

    /** @param callable(): RenderedTelegramText $render */
    private function assertRenderingFailure(callable $render, TelegramMessageRenderingErrorCode $reason): void
    {
        try {
            $render();
            self::fail('Rendering did not reject the text.');
        } catch (TelegramMessageRenderingException $exception) {
            self::assertSame($reason, $exception->reason());
            self::assertSame($reason->value, $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertNull($exception->getPrevious());
            self::assertSame([], get_object_vars($exception));
        }
    }
}
