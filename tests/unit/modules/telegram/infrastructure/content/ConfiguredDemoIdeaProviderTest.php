<?php

declare(strict_types=1);

namespace tests\unit\modules\telegram\infrastructure\content;

use Codeception\Test\Unit;
use modules\telegram\application\port\IIdeaContentProvider;
use modules\telegram\infrastructure\content\ConfiguredDemoIdeaProvider;

final class ConfiguredDemoIdeaProviderTest extends Unit
{
    private const IDS = [
        '019d0000-0000-7000-8000-000000000001',
        '019d0000-0000-7000-8000-000000000002',
        '019d0000-0000-7000-8000-000000000003',
    ];

    public function testFirstCardAndCompleteCycle(): void
    {
        $provider = $this->provider();
        $current = $provider->first();
        self::assertNotNull($current);
        self::assertSame(self::IDS[0], $current->id());

        foreach ([self::IDS[1], self::IDS[2], self::IDS[0]] as $expectedId) {
            $next = $provider->nextAfter($current->id());
            self::assertNotNull($next);
            self::assertSame($expectedId, $next->id());
            $current = $next;
        }
    }

    public function testEveryCardHasMatchingStructuredDetails(): void
    {
        $provider = $this->provider();

        foreach (self::IDS as $index => $id) {
            $card = $provider->findCard($id);
            $details = $provider->findDetails($id);
            self::assertNotNull($card);
            self::assertNotNull($details);
            self::assertSame($id, $card->id());
            self::assertSame('Тестовая карточка ' . ($index + 1), $card->title());
            self::assertEquals($card, $details->card());

            $texts = [
                $card->problem(),
                $card->targetAudience(),
                $card->monetization(),
                $card->cardSummary(),
                $details->problemAndAudience(),
                $details->businessModel(),
                $details->marketAndCompetitors(),
                $details->risksAndAssumptions(),
                $details->mvpPlan(),
                $details->first30Days(),
            ];
            foreach ($texts as $text) {
                self::assertNotSame('', trim($text));
                self::assertSame(1, preg_match('//u', $text));
            }
        }
    }

    public function testUnknownSubjectDoesNotSelectAnotherCard(): void
    {
        $provider = $this->provider();

        foreach (['019d0000-0000-7000-8000-000000000099', '', 'not-a-card', strtoupper(self::IDS[0])] as $subjectId) {
            self::assertNull($provider->findCard($subjectId));
            self::assertNull($provider->findDetails($subjectId));
            self::assertNull($provider->nextAfter($subjectId));
        }
    }

    public function testRepeatedAndInterleavedCallsDoNotChangeContentOrPosition(): void
    {
        $provider = $this->provider();
        $first = $provider->first();
        $details = $provider->findDetails(self::IDS[0]);

        self::assertNotNull($first);
        self::assertNotNull($details);
        self::assertSame(self::IDS[2], $provider->nextAfter(self::IDS[1])?->id());
        self::assertSame(self::IDS[1], $provider->nextAfter(self::IDS[0])?->id());
        self::assertSame(self::IDS[1], $provider->nextAfter(self::IDS[0])->id());

        $provider->findDetails(self::IDS[2]);
        self::assertEquals($first, $provider->first());
        self::assertEquals($details, $provider->findDetails(self::IDS[0]));

        $another = $this->provider();
        self::assertEquals($first, $another->first());
        self::assertEquals($details, $another->findDetails(self::IDS[0]));
    }

    private function provider(): IIdeaContentProvider
    {
        return new ConfiguredDemoIdeaProvider();
    }
}
