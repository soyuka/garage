<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Tool\Tool;

/**
 * A tool whose provider key is absent is not advertised at all: tools/list omits it, tools/call does
 * not know it, and the finder agent (which lists the same server) never sees it.
 */
final class CapabilityGatedToolsTest extends AppTestCase
{
    use McpClientTrait;

    /** @var array{env: mixed, server: mixed, getenv: string|false}|null */
    private ?array $savedToken = null;

    protected function tearDown(): void
    {
        if (null !== $this->savedToken) {
            if (null === $this->savedToken['env']) {
                unset($_ENV['API_PLAQUE_TOKEN']);
            } else {
                $_ENV['API_PLAQUE_TOKEN'] = $this->savedToken['env'];
            }
            if (null === $this->savedToken['server']) {
                unset($_SERVER['API_PLAQUE_TOKEN']);
            } else {
                $_SERVER['API_PLAQUE_TOKEN'] = $this->savedToken['server'];
            }
            putenv(false === $this->savedToken['getenv'] ? 'API_PLAQUE_TOKEN' : 'API_PLAQUE_TOKEN='.$this->savedToken['getenv']);
            $this->savedToken = null;
        }

        parent::tearDown();
    }

    public function testWithThePlateTokenLookupPlateIsListed(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->assertContains('lookup_plate', $this->listed($client, $sessionId));
        $this->assertContains('lookup_plate', $this->finderTools());
    }

    public function testWithoutThePlateTokenLookupPlateIsNotListed(): void
    {
        $this->blankToken();
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $tools = $this->listed($client, $sessionId);
        $this->assertNotContains('lookup_plate', $tools);
        $this->assertContains('decode_vin', $tools, 'only the unconfigured tool goes');
    }

    public function testWithoutThePlateTokenLookupPlateCannotBeCalled(): void
    {
        $this->blankToken();
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $this->callExpectingError($client, $sessionId, 'tools/call', ['name' => 'lookup_plate', 'arguments' => ['plate' => 'XC-302-ZC']], 3);
    }

    public function testWithoutThePlateTokenTheFinderAgentLosesLookupPlate(): void
    {
        $this->blankToken();
        static::createClient();

        $tools = $this->finderTools();
        $this->assertNotContains('lookup_plate', $tools);
        $this->assertContains('decode_vin', $tools);
    }

    /**
     * @return list<string>
     */
    private function listed(Client $client, string $sessionId): array
    {
        $tools = $this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'];
        $this->assertIsArray($tools);

        return array_values(array_map(static fn (array $tool): string => (string) $tool['name'], $tools));
    }

    /**
     * @return list<string>
     */
    private function finderTools(): array
    {
        /** @var ToolboxInterface $toolbox */
        $toolbox = static::getContainer()->get('ai.toolbox.finder');

        return array_values(array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools()));
    }

    private function blankToken(): void
    {
        $this->savedToken ??= ['env' => $_ENV['API_PLAQUE_TOKEN'] ?? null, 'server' => $_SERVER['API_PLAQUE_TOKEN'] ?? null, 'getenv' => getenv('API_PLAQUE_TOKEN')];
        $_ENV['API_PLAQUE_TOKEN'] = '';
        $_SERVER['API_PLAQUE_TOKEN'] = '';
        putenv('API_PLAQUE_TOKEN=');
    }
}
