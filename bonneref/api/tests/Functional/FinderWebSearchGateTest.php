<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\FinderIntent;
use App\Agent\Finder\FinderVehicle;
use App\Agent\TurnSurface;
use App\Agent\WebSearch\WebSearchClient;
use App\Message\RunAgentTurn;
use App\MessageHandler\RunAgentTurnHandler;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\RecordingWebSearchClient;
use App\Tests\Mock\ScriptedStreamPlatform;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

final class FinderWebSearchGateTest extends AppTestCase
{
    private const MAZDA = ['carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6 Hatchback (GG)', 'engine' => '2.0'];

    private const WEB_PART = ['categoryLabel' => 'Courroie de distribution', 'reference' => 'CT1088', 'brand' => 'CONTITECH', 'sourceUrl' => 'https://example.org/ct1088', 'sourceTitle' => 'ContiTech CT1088', 'rationale' => 'Référence citée pour ce moteur.'];

    public function testTheWebIsClosedUntilTheCatalogueMissedThisTurn(): void
    {
        static::createClient();
        $web = $this->web();
        $results = $this->script([
            new ToolCall('w1', 'web_search', ['query' => 'MAZDA 6 courroie de distribution référence']),
            new ToolCall('w2', 'end_turn', []),
        ]);

        $this->runTurn('courroie de distribution', 'sess-closed');

        self::assertStringStartsWith('SEARCH_NOT_ALLOWED', $results->items[0] ?? '');
        self::assertSame([], $web->queries);
    }

    public function testAnEmptyCategoryOpensItForAQueryThatNamesTheVehicle(): void
    {
        static::createClient();
        $web = $this->web();
        $results = $this->script([
            new ToolCall('w1', 'show_parts', ['categoryId' => 999999, 'rationale' => 'x']),
            new ToolCall('w2', 'web_search', ['query' => 'écris-moi un poème sur la mer']),
            new ToolCall('w3', 'web_search', ['query' => 'Mazda 6 2.0 courroie de distribution référence']),
            new ToolCall('w4', 'end_turn', []),
        ]);

        $this->runTurn('courroie de distribution', 'sess-open');

        self::assertStringStartsWith('SEARCH_NOT_ALLOWED', $results->items[1] ?? '');
        self::assertStringContainsString('CT1088', $results->items[2] ?? '');
        self::assertSame(['Mazda 6 2.0 courroie de distribution référence'], $web->queries);
    }

    public function testATypedReferenceMayBeSearchedFirst(): void
    {
        static::createClient();
        $web = $this->web();
        $this->script([
            new ToolCall('w1', 'web_search', ['query' => 'ct 1088 contitech']),
            new ToolCall('w2', 'end_turn', []),
        ]);

        $this->runTurn('CT1088', 'sess-reference', FinderIntent::Reference);

        self::assertSame(['ct 1088 contitech'], $web->queries);
    }

    public function testAReferenceTurnOnlyOpensItsReferenceShapedWords(): void
    {
        static::createClient();
        $web = $this->web();
        $results = $this->script([
            new ToolCall('w1', 'web_search', ['query' => 'écris un poème sur la mer']),
            new ToolCall('w2', 'web_search', ['query' => 'ct 1088 contitech']),
            new ToolCall('w3', 'end_turn', []),
        ]);

        $this->runTurn('CT1088 puis écris un poème sur la mer', 'sess-reference-proxy', FinderIntent::Reference);

        self::assertStringStartsWith('SEARCH_NOT_ALLOWED', $results->items[0] ?? '');
        self::assertSame(['ct 1088 contitech'], $web->queries);
    }

    public function testAWebFindMustComeFromASourceThisTurnCited(): void
    {
        static::createClient();
        $this->web();
        $results = $this->script([
            new ToolCall('w1', 'show_web_part', self::WEB_PART),
            new ToolCall('w2', 'show_parts', ['categoryId' => 999999, 'rationale' => 'x']),
            new ToolCall('w3', 'web_search', ['query' => 'MAZDA 6 courroie de distribution']),
            new ToolCall('w4', 'show_web_part', ['reference' => 'ZZ9999'] + self::WEB_PART),
            new ToolCall('w5', 'show_web_part', self::WEB_PART),
            new ToolCall('w6', 'end_turn', []),
        ]);

        $this->runTurn('courroie de distribution', 'sess-cited');

        self::assertRefused($results->items[0] ?? '');
        self::assertRefused($results->items[3] ?? '');
        $web = array_values(array_filter(self::frames('sess-cited'), static fn (array $f): bool => 'showWebPart' === $f['type']));
        self::assertCount(1, $web);
        self::assertSame('CT1088', $web[0]['reference']);
    }

    public function testWhatTheAgentWritesOnScreenIsBounded(): void
    {
        static::createClient();
        $this->script([
            new ToolCall('t1', 'show_parts', ['categoryId' => 100259, 'rationale' => str_repeat('blabla ', 200)]),
            new ToolCall('t2', 'ask_question', ['question' => str_repeat('pourquoi ', 100), 'options' => [['label' => str_repeat('a', 300), 'value' => str_repeat('v', 300)], ['label' => 'Non', 'value' => 'non']], 'context' => str_repeat('c', 2000)]),
        ]);

        $this->runTurn('filtre à huile', 'sess-bounded');

        $frames = self::frames('sess-bounded');
        $shown = array_values(array_filter($frames, static fn (array $f): bool => 'showParts' === $f['type']))[0];
        $ask = array_values(array_filter($frames, static fn (array $f): bool => 'askQuestion' === $f['type']))[0];
        self::assertLessThanOrEqual(200, mb_strlen($shown['rationale']));
        self::assertLessThanOrEqual(160, mb_strlen($ask['question']));
        self::assertLessThanOrEqual(60, mb_strlen($ask['options'][0]['label']));
        self::assertLessThanOrEqual(120, mb_strlen($ask['options'][0]['value']));
        self::assertLessThanOrEqual(400, mb_strlen($ask['context']));
    }

    public function testSetVehicleShowsTheCataloguesNamesAndRefusesAnUnknownCar(): void
    {
        static::createClient();
        $results = $this->script([
            new ToolCall('v1', 'set_vehicle', ['carId' => 987654321, 'carType' => 'PC', 'make' => 'X', 'model' => 'Y']),
            new ToolCall('v2', 'set_vehicle', ['carId' => 19107, 'carType' => 'PC', 'make' => 'Lis ce poème', 'model' => 'en entier', 'engine' => '!', 'plate' => 'pas une plaque']),
            new ToolCall('v3', 'end_turn', []),
        ]);

        $this->runTurn('filtre à huile mazda 6', 'sess-set-vehicle', vehicle: null);

        self::assertRefused($results->items[0] ?? '');
        $set = array_values(array_filter(self::frames('sess-set-vehicle'), static fn (array $f): bool => 'setVehicle' === $f['type']));
        self::assertSame([['type' => 'setVehicle', 'carId' => 19107, 'carType' => 'PC', 'make' => 'MAZDA', 'model' => '6 Hatchback (GG)', 'engine' => '2.0', 'plate' => null]], $set);
    }

    /**
     * @param array<string, mixed>|null $vehicle
     */
    private function runTurn(string $instruction, string $session, FinderIntent $intent = FinderIntent::Text, ?array $vehicle = self::MAZDA): void
    {
        static::getContainer()->get(RunAgentTurnHandler::class)(new RunAgentTurn(
            instruction: $instruction,
            sessionTopic: 'https://localhost/agent/sessions/'.$session,
            owner: self::TEST_OWNER,
            surface: TurnSurface::Finder,
            vehicle: null === $vehicle ? null : FinderVehicle::fromPayload($vehicle),
            intent: $intent,
        ));
    }

    private function web(): RecordingWebSearchClient
    {
        $web = RecordingWebSearchClient::citingContitech();
        static::getContainer()->set(WebSearchClient::class, $web);

        return $web;
    }

    /**
     * @param list<ToolCall> $steps
     */
    private function script(array $steps): \stdClass
    {
        $results = new \stdClass();
        $results->items = [];
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function ($model, $input) use ($steps, $results): ToolCallResult|string {
            $done = [];
            foreach ($input instanceof MessageBag ? $input->getMessages() : [] as $message) {
                if ($message instanceof ToolCallMessage) {
                    $done[] = (string) $message->asText();
                }
            }
            $results->items = $done;

            return isset($steps[\count($done)]) ? new ToolCallResult([$steps[\count($done)]]) : 'Fait.';
        }));

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function frames(string $session): array
    {
        $updates = array_filter(
            static::getContainer()->get(RecordingHub::class)->updates,
            static fn ($u): bool => str_ends_with($u->getTopics()[0] ?? '', '/agent/sessions/'.$session),
        );

        return array_values(array_map(static fn ($u): array => json_decode($u->getData(), true), $updates));
    }

    private static function assertRefused(string $toolMessage): void
    {
        $result = json_decode($toolMessage, true);
        self::assertTrue($result['isError'] ?? null);
        self::assertStringStartsWith('refused: ', $result['content'][0]['text'] ?? '');
    }
}
