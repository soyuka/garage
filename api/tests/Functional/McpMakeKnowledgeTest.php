<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MakeKnowledge;
use App\Entity\MakeKnowledgeSource;
use App\Mcp\Agent\McpToolbox;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\AI\Platform\Tool\Tool;

final class McpMakeKnowledgeTest extends AppTestCase
{
    use McpClientTrait;

    private function seed(): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);

        $dm->persist(new MakeKnowledge(
            ['Peugeot', 'Citroën'],
            'numero-organisation',
            'Numéro d\'organisation',
            'Le compteur de jours.',
            [new MakeKnowledgeSource('https://example.test/a', 'Forum', true)],
            true,
        ));
        $dm->persist(new MakeKnowledge(['PEUGEOT'], 'ou-trouver-opr', 'Où trouver l\'OPR', 'Sur le montant de porte.', [], false));
        $dm->persist(new MakeKnowledge(['RENAULT'], 'rapport-technique', 'Note technique', 'Hors sujet.', [], true));
        $dm->flush();
        $dm->clear();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function get(array $arguments): array
    {
        $this->seed();
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'get_make_knowledge',
            'arguments' => $arguments,
        ], 2);

        return $result['result']['structuredContent'];
    }

    public function testListsTheTopicsOfAMakeMatchedCaseAndAccentInsensitively(): void
    {
        $content = $this->get(['make' => 'CITROËN']);

        $this->assertSame('ok', $content['status']);
        $this->assertSame('CITROEN', $content['make']);
        $this->assertSame([['topic' => 'numero-organisation', 'title' => 'Numéro d\'organisation']], $content['topics']);
    }

    public function testListsEveryTopicOfAMakeSortedByTopic(): void
    {
        $content = $this->get(['make' => 'peugeot']);

        $this->assertSame(['numero-organisation', 'ou-trouver-opr'], array_column($content['topics'], 'topic'));
    }

    public function testReturnsTheFullEntryForATopic(): void
    {
        $content = $this->get(['make' => 'Citroën', 'topic' => 'numero-organisation']);

        $this->assertSame('ok', $content['status']);
        $this->assertSame('Le compteur de jours.', $content['body']);
        $this->assertSame('Numéro d\'organisation', $content['title']);
        $this->assertTrue($content['verified']);
        $this->assertSame([['url' => 'https://example.test/a', 'label' => 'Forum', 'verified' => true]], $content['sources']);
        $this->assertArrayHasKey('updatedAt', $content);
    }

    public function testUnknownTopicIsANotFoundThatListsWhatExists(): void
    {
        $content = $this->get(['make' => 'Peugeot', 'topic' => 'nope']);

        $this->assertSame('not_found', $content['status']);
        $this->assertSame(['numero-organisation', 'ou-trouver-opr'], array_column($content['topics'], 'topic'));
    }

    public function testMakeWithoutKnowledgeIsANotFound(): void
    {
        $content = $this->get(['make' => 'Fiat']);

        $this->assertSame('not_found', $content['status']);
        $this->assertSame([], $content['topics']);
    }

    public function testRequiresAMake(): void
    {
        $content = $this->get(['make' => ' ']);

        $this->assertSame('invalid_input', $content['status']);
    }

    public function testBothToolsAreAdvertisedAndReadOnly(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $list = $this->call($client, $sessionId, 'tools/list', [], 2);
        $names = array_column($list['result']['tools'], 'name');

        $this->assertContains('get_make_knowledge', $names);
        $this->assertContains('decode_build_code', $names);
        $this->assertNotContains('set_make_knowledge', $names);
        $this->assertSame([], array_filter($names, static fn (string $n): bool => str_contains($n, 'make_knowledge') && 'get_make_knowledge' !== $n));
    }

    public function testTheInAppAgentSeesTheTool(): void
    {
        static::createClient();
        $toolbox = static::getContainer()->get(McpToolbox::class);

        $names = array_map(static fn (Tool $t) => $t->getName(), $toolbox->getTools());

        $this->assertContains('get_make_knowledge', $names);
    }
}
