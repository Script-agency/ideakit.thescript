<?php

declare(strict_types=1);

namespace modules\telegram\presentation\keyboard;

final class TelegramReplyKeyboard
{
    public function __construct(private readonly string $text)
    {
    }

    /**
     * @return array{
     *     keyboard: array{array{array{text: string}}},
     *     resize_keyboard: true,
     *     one_time_keyboard: false,
     *     is_persistent: true
     * }
     */
    public function toArray(): array
    {
        return [
            'keyboard' => [[['text' => $this->text]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'is_persistent' => true,
        ];
    }
}
