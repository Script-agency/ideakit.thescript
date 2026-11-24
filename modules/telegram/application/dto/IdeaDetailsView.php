<?php

declare(strict_types=1);

namespace modules\telegram\application\dto;

final class IdeaDetailsView
{
    public function __construct(
        private readonly IdeaCardView $card,
        private readonly string $problemAndAudience,
        private readonly string $businessModel,
        private readonly string $marketAndCompetitors,
        private readonly string $risksAndAssumptions,
        private readonly string $mvpPlan,
        private readonly string $first30Days,
    ) {
    }

    public function card(): IdeaCardView
    {
        return $this->card;
    }

    public function problemAndAudience(): string
    {
        return $this->problemAndAudience;
    }

    public function businessModel(): string
    {
        return $this->businessModel;
    }

    public function marketAndCompetitors(): string
    {
        return $this->marketAndCompetitors;
    }

    public function risksAndAssumptions(): string
    {
        return $this->risksAndAssumptions;
    }

    public function mvpPlan(): string
    {
        return $this->mvpPlan;
    }

    public function first30Days(): string
    {
        return $this->first30Days;
    }
}
