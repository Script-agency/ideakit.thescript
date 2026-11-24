<?php

declare(strict_types=1);

namespace modules\telegram\presentation\message;

use modules\telegram\application\dto\IdeaCardView;
use modules\telegram\application\dto\IdeaDetailsView;
use modules\telegram\presentation\enum\TelegramMessageRenderingErrorCode;
use modules\telegram\presentation\exception\TelegramMessageRenderingException;

final class TelegramMessageRenderer
{
    private const MAX_TEXT_UNITS = 4096;

    /** @throws TelegramMessageRenderingException */
    public function card(IdeaCardView $card): RenderedTelegramText
    {
        return $this->render([
            ['text' => $card->title(), 'bold' => true],
            ['text' => "\n\n" . $card->cardSummary() . "\n\n"],
            ['text' => 'Проблема:', 'bold' => true],
            ['text' => "\n" . $card->problem() . "\n\n"],
            ['text' => 'Аудитория:', 'bold' => true],
            ['text' => "\n" . $card->targetAudience() . "\n\n"],
            ['text' => 'Монетизация:', 'bold' => true],
            ['text' => "\n" . $card->monetization()],
        ]);
    }

    /** @throws TelegramMessageRenderingException */
    public function details(IdeaDetailsView $details): RenderedTelegramText
    {
        return $this->render([
            ['text' => $details->card()->title(), 'bold' => true],
            ['text' => "\n\n"],
            ['text' => 'Проблема и аудитория', 'bold' => true],
            ['text' => "\n" . $details->problemAndAudience() . "\n\n"],
            ['text' => 'Бизнес-модель', 'bold' => true],
            ['text' => "\n" . $details->businessModel() . "\n\n"],
            ['text' => 'Рынок и конкуренты', 'bold' => true],
            ['text' => "\n" . $details->marketAndCompetitors() . "\n\n"],
            ['text' => 'Риски и предположения', 'bold' => true],
            ['text' => "\n" . $details->risksAndAssumptions() . "\n\n"],
            ['text' => 'План минимальной версии продукта', 'bold' => true],
            ['text' => "\n" . $details->mvpPlan() . "\n\n"],
            ['text' => 'Первые 30 дней', 'bold' => true],
            ['text' => "\n" . $details->first30Days()],
        ]);
    }

    public function demoGreeting(): RenderedTelegramText
    {
        return $this->render([
            ['text' => 'Добро пожаловать в IDEAKIT!', 'bold' => true],
            [
                'text' => "\n\nСейчас доступен демонстрационный режим: карточки тестовые, заявки не отправляются.\n\n"
                    . 'Нажимайте «Следующая идея» для просмотра карточек. У карточки доступны «Детали» и «Заказать у нас».',
            ],
        ]);
    }

    public function demoCommentPrompt(): RenderedTelegramText
    {
        return $this->render([
            ['text' => 'Демонстрационное оформление', 'bold' => true],
            [
                'text' => "\n\nВведите комментарий к карточке — до 2000 символов.\n\n"
                    . 'Заявка не будет отправлена. Для отмены нажмите «Отменить».',
            ],
        ]);
    }

    /** @throws TelegramMessageRenderingException */
    public function demoCommentConfirmation(string $comment): RenderedTelegramText
    {
        return $this->render([
            ['text' => 'Проверьте комментарий:', 'bold' => true],
            ['text' => "\n\n" . $comment . "\n\nЭто демонстрация: заявка не будет отправлена."],
        ]);
    }

    public function demoOrderCompleted(): RenderedTelegramText
    {
        return $this->render([['text' => 'Демонстрация завершена. Заявка не отправлена.']]);
    }

    public function orderCancelled(): RenderedTelegramText
    {
        return $this->render([['text' => 'Оформление отменено.']]);
    }

    public function draftExpired(): RenderedTelegramText
    {
        return $this->render([
            ['text' => 'Время хранения комментария истекло. Нажмите «Заказать у нас», чтобы начать заново.'],
        ]);
    }

    public function browseHint(): RenderedTelegramText
    {
        return $this->render([['text' => 'Используйте кнопку «Следующая идея» или кнопки под карточкой.']]);
    }

    public function finishOrderHint(): RenderedTelegramText
    {
        return $this->render([['text' => 'Завершите текущее оформление или нажмите «Отменить».']]);
    }

    public function invalidComment(): RenderedTelegramText
    {
        return $this->render([['text' => 'Введите непустой комментарий длиной до 2000 символов.']]);
    }

    public function cardUnavailable(): RenderedTelegramText
    {
        return $this->render([['text' => 'Карточка недоступна. Нажмите «Следующая идея», чтобы продолжить просмотр.']]);
    }

    public function demoPdfUnavailable(): RenderedTelegramText
    {
        return $this->render([['text' => 'PDF будет доступен после запуска каталога.']]);
    }

    /** @param list<array{text: string, bold?: true}> $parts */
    private function render(array $parts): RenderedTelegramText
    {
        $plainText = '';
        // Некорректная кодировка любого фрагмента имеет приоритет перед проверкой размера.
        foreach ($parts as $part) {
            if (!mb_check_encoding($part['text'], 'UTF-8')) {
                throw new TelegramMessageRenderingException(TelegramMessageRenderingErrorCode::INVALID_TEXT);
            }
            $plainText .= $part['text'];
        }

        if ($plainText === '') {
            throw new TelegramMessageRenderingException(TelegramMessageRenderingErrorCode::INVALID_TEXT);
        }
        $units = intdiv(strlen(mb_convert_encoding($plainText, 'UTF-16LE', 'UTF-8')), 2);
        if ($units > self::MAX_TEXT_UNITS) {
            throw new TelegramMessageRenderingException(TelegramMessageRenderingErrorCode::TEXT_TOO_LONG);
        }

        $html = '';
        foreach ($parts as $part) {
            $text = htmlspecialchars($part['text'], ENT_QUOTES | ENT_HTML401, 'UTF-8', true);
            $html .= ($part['bold'] ?? false) ? '<b>' . $text . '</b>' : $text;
        }

        return new RenderedTelegramText($html);
    }
}
