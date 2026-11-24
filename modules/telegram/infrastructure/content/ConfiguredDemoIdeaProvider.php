<?php

declare(strict_types=1);

namespace modules\telegram\infrastructure\content;

use modules\telegram\application\dto\IdeaCardView;
use modules\telegram\application\dto\IdeaDetailsView;
use modules\telegram\application\port\IIdeaContentProvider;

/**
 * @phpstan-type DemoCard array{
 *     id: string,
 *     title: string,
 *     problem: string,
 *     targetAudience: string,
 *     monetization: string,
 *     cardSummary: string
 * }
 */
final class ConfiguredDemoIdeaProvider implements IIdeaContentProvider
{
    /** @var non-empty-list<DemoCard> */
    private const CARDS = [
        [
            'id' => '019d0000-0000-7000-8000-000000000001',
            'title' => 'Тестовая карточка 1',
            'problem' => 'Тестовая проблема для карточки 1.',
            'targetAudience' => 'Тестовая аудитория карточки 1.',
            'monetization' => 'Тестовая модель монетизации карточки 1.',
            'cardSummary' => 'Синтетические данные для проверки первой карточки.',
        ],
        [
            'id' => '019d0000-0000-7000-8000-000000000002',
            'title' => 'Тестовая карточка 2',
            'problem' => 'Тестовая проблема для карточки 2.',
            'targetAudience' => 'Тестовая аудитория карточки 2.',
            'monetization' => 'Тестовая модель монетизации карточки 2.',
            'cardSummary' => 'Синтетические данные для проверки второй карточки.',
        ],
        [
            'id' => '019d0000-0000-7000-8000-000000000003',
            'title' => 'Тестовая карточка 3',
            'problem' => 'Тестовая проблема для карточки 3.',
            'targetAudience' => 'Тестовая аудитория карточки 3.',
            'monetization' => 'Тестовая модель монетизации карточки 3.',
            'cardSummary' => 'Синтетические данные для проверки третьей карточки.',
        ],
    ];

    public function first(): IdeaCardView
    {
        return $this->cardView(self::CARDS[0]);
    }

    public function findCard(string $subjectId): ?IdeaCardView
    {
        $index = $this->indexOf($subjectId);

        return $index === null ? null : $this->cardView(self::CARDS[$index]);
    }

    public function findDetails(string $subjectId): ?IdeaDetailsView
    {
        $card = $this->findCard($subjectId);
        if ($card === null) {
            return null;
        }

        return new IdeaDetailsView(
            $card,
            'Тестовое описание проблемы и аудитории: ' . $card->title() . '.',
            'Тестовое описание бизнес-модели: ' . $card->title() . '.',
            'Тестовый раздел о рынке и конкурентах без фактических данных.',
            'Тестовый раздел о рисках и предположениях.',
            'Тестовый план минимальной версии продукта.',
            'Тестовый план первых 30 дней.',
        );
    }

    public function nextAfter(string $subjectId): ?IdeaCardView
    {
        $index = $this->indexOf($subjectId);
        if ($index === null) {
            return null;
        }

        $nextIndex = ($index + 1) % count(self::CARDS);

        return $this->cardView(self::CARDS[$nextIndex]);
    }

    private function indexOf(string $subjectId): ?int
    {
        foreach (self::CARDS as $index => $card) {
            if ($card['id'] === $subjectId) {
                return $index;
            }
        }

        return null;
    }

    /** @param DemoCard $card */
    private function cardView(array $card): IdeaCardView
    {
        return new IdeaCardView(
            $card['id'],
            $card['title'],
            $card['problem'],
            $card['targetAudience'],
            $card['monetization'],
            $card['cardSummary'],
        );
    }
}
