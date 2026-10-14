<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\Agent\McpToolbox;
use Symfony\AI\Platform\Tool\Tool;

final class McpDecodeBuildCodeTest extends AppTestCase
{
    use McpClientTrait;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function decode(array $arguments): array
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $result = $this->call($client, $sessionId, 'tools/call', [
            'name' => 'decode_build_code',
            'arguments' => $arguments,
        ], 2);

        return $result['result']['structuredContent'];
    }

    public function testDecodesAnApvForACitroen(): void
    {
        $content = $this->decode(['make' => 'Citroën', 'code' => '102638910232']);

        $this->assertSame('decoded', $content['status']);
        $this->assertSame('apv', $content['codeKind']);
        $this->assertSame('10263', $content['organisationNumber']);
        $this->assertSame('2004-12-14', $content['date']);
        $this->assertSame('89', $content['plantCode']);
        $this->assertSame('Sochaux', $content['plantName']);
        $this->assertNotEmpty($content['caveats']);
        $this->assertStringStartsWith('https://www.forum-peugeot.com/', $content['sources'][0]);
    }

    public function testReversesADateToAnOrganisationNumber(): void
    {
        $content = $this->decode(['make' => 'PEUGEOT', 'date' => '2004-09-15']);

        $this->assertSame('decoded', $content['status']);
        $this->assertSame('10173', $content['organisationNumber']);
    }

    public function testRequiresCodeOrDate(): void
    {
        $content = $this->decode(['make' => 'PEUGEOT']);

        $this->assertSame('invalid_input', $content['status']);
    }

    public function testRejectsAnInvalidCodeWithADomainError(): void
    {
        $content = $this->decode(['make' => 'PEUGEOT', 'code' => 'nope']);

        $this->assertSame('invalid_input', $content['status']);
        $this->assertStringContainsString('nope', $content['reason']);
    }

    public function testRequiresAMake(): void
    {
        $content = $this->decode(['code' => '10263']);

        $this->assertSame('invalid_input', $content['status']);
    }

    public function testRejectsAnInvalidDate(): void
    {
        $content = $this->decode(['make' => 'PEUGEOT', 'date' => '15/09/2004']);

        $this->assertSame('invalid_input', $content['status']);
    }

    public function testUnsupportedMakeIsReportedNotThrown(): void
    {
        $content = $this->decode(['make' => 'Renault', 'code' => '10263']);

        $this->assertSame('unsupported_make', $content['status']);
    }

    public function testToolSchemaIsGeneratedFromTheInputDto(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $list = $this->call($client, $sessionId, 'tools/list', [], 2);
        $tool = null;
        foreach ($list['result']['tools'] as $candidate) {
            if ('decode_build_code' === $candidate['name']) {
                $tool = $candidate;
            }
        }

        $this->assertNotNull($tool, 'decode_build_code must be advertised');
        $this->assertSame('string', $tool['inputSchema']['properties']['make']['type']);
        $this->assertArrayHasKey('code', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('date', $tool['inputSchema']['properties']);
        $this->assertStringContainsString("n° d'organisation", $tool['description']);
    }

    public function testTheInAppAgentSeesTheTool(): void
    {
        static::createClient();
        $toolbox = static::getContainer()->get(McpToolbox::class);

        $names = array_map(static fn (Tool $t) => $t->getName(), $toolbox->getTools());

        $this->assertContains('decode_build_code', $names);
    }
}
