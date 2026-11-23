<?php

declare(strict_types=1);

namespace modules\telegram\presentation\keyboard;

final class TelegramInlineKeyboard
{
    public function __construct(
        private readonly string $firstText,
        private readonly string $firstCallbackData,
        private readonly string $secondText,
        private readonly string $secondCallbackData,
    ) {
    }

    /**
     * @return array{inline_keyboard: array{array{
     *     array{text: string, callback_data: string},
     *     array{text: string, callback_data: string}
     * }}}
     */
    public function toArray(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => $this->firstText, 'callback_data' => $this->firstCallbackData],
                ['text' => $this->secondText, 'callback_data' => $this->secondCallbackData],
            ]],
        ];
    }
}
