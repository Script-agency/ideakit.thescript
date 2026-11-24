<?php

declare(strict_types=1);

namespace modules\telegram\presentation\message;

final class RenderedTelegramText
{
    public function __construct(private readonly string $text)
    {
    }

    public function text(): string
    {
        return $this->text;
    }

    public function parseMode(): string
    {
        return 'HTML';
    }
}
