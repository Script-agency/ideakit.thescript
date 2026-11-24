<?php

declare(strict_types=1);

namespace modules\telegram\application\dto;

final class IdeaCardView
{
    public function __construct(
        private readonly string $id,
        private readonly string $title,
        private readonly string $problem,
        private readonly string $targetAudience,
        private readonly string $monetization,
        private readonly string $cardSummary,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function problem(): string
    {
        return $this->problem;
    }

    public function targetAudience(): string
    {
        return $this->targetAudience;
    }

    public function monetization(): string
    {
        return $this->monetization;
    }

    public function cardSummary(): string
    {
        return $this->cardSummary;
    }
}
