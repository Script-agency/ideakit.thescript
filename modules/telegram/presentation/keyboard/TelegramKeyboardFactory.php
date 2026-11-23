<?php

declare(strict_types=1);

namespace modules\telegram\presentation\keyboard;

use modules\telegram\application\dto\IdeaCardView;
use modules\telegram\application\dto\IdeaDetailsView;
use modules\telegram\application\port\ITelegramCallbackEncoder;

final class TelegramKeyboardFactory
{
    public function __construct(private readonly ITelegramCallbackEncoder $encoder)
    {
    }

    public function browsing(): TelegramReplyKeyboard
    {
        return new TelegramReplyKeyboard('Следующая идея');
    }

    public function commentCancellation(): TelegramReplyKeyboard
    {
        return new TelegramReplyKeyboard('Отменить');
    }

    public function forCard(
        IdeaCardView $card,
        string $botKey,
        string $telegramIdentityProfileId,
    ): TelegramInlineKeyboard {
        $details = $this->encoder->details($card->id(), $botKey, $telegramIdentityProfileId);
        $order = $this->encoder->order($card->id(), $botKey, $telegramIdentityProfileId);

        return new TelegramInlineKeyboard('Детали', $details, 'Заказать у нас', $order);
    }

    public function forDetails(
        IdeaDetailsView $details,
        string $botKey,
        string $telegramIdentityProfileId,
    ): TelegramInlineKeyboard {
        $cardId = $details->card()->id();
        $pdf = $this->encoder->pdf($cardId, $botKey, $telegramIdentityProfileId);
        $order = $this->encoder->order($cardId, $botKey, $telegramIdentityProfileId);

        return new TelegramInlineKeyboard('Получить PDF', $pdf, 'Заказать у нас', $order);
    }

    public function forConfirmation(
        int $interactionRevision,
        string $botKey,
        string $telegramIdentityProfileId,
    ): TelegramInlineKeyboard {
        $confirm = $this->encoder->confirmOrder($interactionRevision, $botKey, $telegramIdentityProfileId);
        $cancel = $this->encoder->cancelOrder($interactionRevision, $botKey, $telegramIdentityProfileId);

        return new TelegramInlineKeyboard('Подтвердить', $confirm, 'Отменить', $cancel);
    }
}
