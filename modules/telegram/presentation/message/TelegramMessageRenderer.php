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

    /** @param list<array{text: string, bold?: true}> $parts */
    private function render(array $parts): RenderedTelegramText
    {
        $plainText = '';
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
