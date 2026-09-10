<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Toolbox;

use App\I18n\LocaleContext;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Agent\Toolbox\CompositeToolbox;
use App\Agent\Ui\RenderIntentTools;
use App\Tests\Mock\TestDashboardChannel;
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
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        return new CompositeToolbox($render, $mcp, new StepNarrator($tools, new LocaleContext(new RequestStack()), static fn (int $n): int => 0));
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
            [['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…', 'tool' => 'get_service_parts']],
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

    /** A call the stream already announced (EarlyStepNarrationListener) must not narrate a second time here. */
    public function testDoesNotReNarrateAnMcpCallAlreadyAnnouncedByTheStream(): void
    {
        $published = new \ArrayObject();
        $narrator = new StepNarrator($this->renderTools($published), new LocaleContext(new RequestStack()), static fn (int $n): int => 0);
        $toolbox = new CompositeToolbox($this->renderToolboxStub(), $this->mcpToolboxExposing('get_service_parts'), $narrator);
        // Simulate what EarlyStepNarrationListener would already have done from the stream.
        $narrator->announce('id-3', 'get_service_parts');
        $published->exchangeArray([]);

        $toolbox->execute(new ToolCall('id-3', 'get_service_parts', []));

        $this->assertSame([], $published->getArrayCopy());
    }

    /** An MCP call the stream never announced still narrates at execute time — the fallback path. */
    public function testStillNarratesAnMcpCallTheStreamNeverAnnounced(): void
    {
        $published = new \ArrayObject();
        $toolbox = $this->toolbox($published, $this->mcpToolboxExposing('get_service_parts'));

        $toolbox->execute(new ToolCall('id-4', 'get_service_parts', []));

        $this->assertSame(
            [['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…', 'tool' => 'get_service_parts']],
            $published->getArrayCopy(),
        );
    }

    /** An announced narrate_step acks without executing — its sentence is already on the ticker. */
    public function testAnAnnouncedNarrateStepReturnsOkWithoutCallingTheRenderToolbox(): void
    {
        $published = new \ArrayObject();
        $renderCalled = false;
        $render = $this->createStub(ToolboxInterface::class);
        $render->method('getTools')->willReturn([]);
        $render->method('execute')->willReturnCallback(function (ToolCall $c) use (&$renderCalled): ToolResult {
            $renderCalled = true;

            return new ToolResult($c, 'render-ok');
        });

        $narrator = new StepNarrator($this->renderTools($published), new LocaleContext(new RequestStack()), static fn (int $n): int => 0);
        $narrator->announceStep('id-5', 'Je compare les marques…');
        $published->exchangeArray([]);
        $toolbox = new CompositeToolbox($render, $this->mcpToolboxExposing('get_service_parts'), $narrator);

        $result = $toolbox->execute(new ToolCall('id-5', 'narrate_step', ['message' => 'Je compare les marques…']));

        $this->assertSame('ok', $result->getResult());
        $this->assertFalse($renderCalled);
    }

    /** An unannounced narrate_step still executes as today — it is the one publishing its sentence. */
    public function testAnUnannouncedNarrateStepStillExecutesAgainstTheRenderToolbox(): void
    {
        $published = new \ArrayObject();
        $toolbox = $this->toolbox($published, $this->mcpToolboxExposing('get_service_parts'));

        $result = $toolbox->execute(new ToolCall('id-6', 'narrate_step', ['message' => 'Je compare les marques…']));

        $this->assertSame('render-ok', $result->getResult());
    }

    private function renderToolboxStub(): ToolboxInterface
    {
        $render = $this->createStub(ToolboxInterface::class);
        $render->method('getTools')->willReturn([]);
        $render->method('execute')->willReturnCallback(
            static fn (ToolCall $c): ToolResult => new ToolResult($c, 'render-ok'),
        );

        return $render;
    }

    /**
     * @param \ArrayObject<int, array<string, mixed>> $published
     */
    private function renderTools(\ArrayObject $published): RenderIntentTools
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        return $tools;
    }
}
