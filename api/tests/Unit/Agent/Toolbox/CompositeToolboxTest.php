<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Toolbox;

use App\Agent\Toolbox\CompositeToolbox;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\StepNarrator;
use App\Mcp\Agent\McpToolbox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class CompositeToolboxTest extends TestCase
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $published
     */
    private function toolbox(\ArrayObject $published, McpToolbox $mcp): CompositeToolbox
    {
        $render = $this->createStub(ToolboxInterface::class);
        $render->method('getTools')->willReturn([]);
        $render->method('execute')->willReturnCallback(
            static fn (ToolCall $c): ToolResult => new ToolResult($c, 'render-ok'),
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class));
        $tools->bindSession('https://localhost/agent/sessions/abc');

        return new CompositeToolbox($render, $mcp, new StepNarrator($tools));
    }

    private function mcpToolboxExposing(string $name): McpToolbox
    {
        $mcp = $this->createStub(McpToolbox::class);
        $mcp->method('getTools')->willReturn([
            new Tool(new ExecutionReference(McpToolbox::class, 'execute'), $name, ''),
        ]);
        $mcp->method('execute')->willReturnCallback(
            static fn (ToolCall $c): ToolResult => new ToolResult($c, 'mcp-ok'),
        );

        return $mcp;
    }

    public function testNarratesBeforeRunningAGarageTool(): void
    {
        $published = new \ArrayObject();
        $toolbox = $this->toolbox($published, $this->mcpToolboxExposing('get_service_parts'));

        $toolbox->execute(new ToolCall('id-1', 'get_service_parts', []));

        $this->assertSame(
            [['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…']],
            $published->getArrayCopy(),
        );
    }

    public function testDoesNotNarrateRenderIntentTools(): void
    {
        $published = new \ArrayObject();
        // The render tool `toast` is NOT in the MCP catalog, so it routes to the render toolbox and
        // must not be narrated (it is itself a UI intent, not a work step).
        $toolbox = $this->toolbox($published, $this->mcpToolboxExposing('get_service_parts'));

        $toolbox->execute(new ToolCall('id-2', 'toast', ['message' => 'fait']));

        $this->assertSame([], $published->getArrayCopy());
    }
}
