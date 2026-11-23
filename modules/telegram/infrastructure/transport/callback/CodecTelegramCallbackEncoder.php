<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\transport\callback;

use modules\telegram\application\port\ITelegramCallbackEncoder;

final class CodecTelegramCallbackEncoder implements ITelegramCallbackEncoder
{
    public function __construct(private readonly TelegramCallbackCodec $codec)
    {
    }

    public function details(string $cardId, string $botKey, string $telegramIdentityProfileId): string
    {
        return $this->codec->encodeCard(CallbackAction::DETAILS, $cardId, $botKey, $telegramIdentityProfileId);
    }

    public function order(string $cardId, string $botKey, string $telegramIdentityProfileId): string
    {
        return $this->codec->encodeCard(CallbackAction::ORDER, $cardId, $botKey, $telegramIdentityProfileId);
    }

    public function pdf(string $cardId, string $botKey, string $telegramIdentityProfileId): string
    {
        return $this->codec->encodeCard(CallbackAction::PDF, $cardId, $botKey, $telegramIdentityProfileId);
    }

    public function confirmOrder(int $interactionRevision, string $botKey, string $telegramIdentityProfileId): string
    {
        return $this->codec->encodeRevision(
            CallbackAction::CONFIRM_ORDER,
            $interactionRevision,
            $botKey,
            $telegramIdentityProfileId,
        );
    }

    public function cancelOrder(int $interactionRevision, string $botKey, string $telegramIdentityProfileId): string
    {
        return $this->codec->encodeRevision(
            CallbackAction::CANCEL_ORDER,
            $interactionRevision,
            $botKey,
            $telegramIdentityProfileId,
        );
    }
}
