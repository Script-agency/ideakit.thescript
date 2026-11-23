<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use InvalidArgumentException;

interface ITelegramCallbackEncoder
{
    /** @throws InvalidArgumentException */
    public function details(string $cardId, string $botKey, string $telegramIdentityProfileId): string;

    /** @throws InvalidArgumentException */
    public function order(string $cardId, string $botKey, string $telegramIdentityProfileId): string;

    /** @throws InvalidArgumentException */
    public function pdf(string $cardId, string $botKey, string $telegramIdentityProfileId): string;

    /** @throws InvalidArgumentException */
    public function confirmOrder(int $interactionRevision, string $botKey, string $telegramIdentityProfileId): string;

    /** @throws InvalidArgumentException */
    public function cancelOrder(int $interactionRevision, string $botKey, string $telegramIdentityProfileId): string;
}
