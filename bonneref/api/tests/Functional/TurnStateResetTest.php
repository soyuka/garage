<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Finder\FinderRenderTools;
use App\Agent\Finder\FinderVehicle;
use App\Agent\TurnSettlement;
use App\Agent\Ui\DashboardChannel;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\RepeatedToolFailureGuard;
use App\Agent\Ui\StepNarrator;
use App\Agent\Ui\TurnActivityTracker;
use App\Agent\WebSearch\WebSearchGate;
use App\Agent\WebSearchBudget;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Mcp\ToolError;
use App\Message\RunAgentTurn;
use App\Tests\Mock\RecordingHub;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\ServicesResetter;

final class TurnStateResetTest extends AppTestCase
{
    private const string PREVIOUS_SESSION = 'https://localhost/finder/sessions/previous-turn';

    public function testKernelResetUnbindsEveryPerTurnHolder(): void
    {
        static::bootKernel();
        $container = static::getContainer();

        $locale = $container->get(LocaleContext::class);
        $channel = $container->get(DashboardChannel::class);
        $tools = $container->get(RenderIntentTools::class);
        $narrator = $container->get(StepNarrator::class);
        $budget = $container->get(WebSearchBudget::class);
        $tracker = $container->get(TurnActivityTracker::class);
        $guard = $container->get(RepeatedToolFailureGuard::class);
        $gate = $container->get(WebSearchGate::class);
        $finder = $container->get(FinderRenderTools::class);

        $locale->set(Locale::Fr);
        $channel->bindSession(self::PREVIOUS_SESSION);
        $tools->toast('pending', 'info');
        $narrator->announce('call-1', 'list_makes');
        $budget->record(99);
        $tracker(new ToolCallsExecuted([new ToolResult(new ToolCall('call-2', 'end_turn', []), 'ok')]));
        $gate->reset(['CT1088']);
        $finder->bindVehicle(new FinderVehicle(12345, make: 'PEUGEOT', model: '308'));
        $failure = new ToolCallsExecuted([new ToolResult(new ToolCall('call-3', 'show_parts', ['categoryId' => 1]), new CallToolResult([new TextContent('boom')], true))]);
        $guard($failure);
        $guard($failure);
        $guard($failure);
        $guard($failure);

        $resetter = $container->get('services_resetter');
        $this->assertInstanceOf(ServicesResetter::class, $resetter);
        $resetter->reset();

        $this->assertSame(Locale::DEFAULT, $locale->current());
        $this->assertNull($channel->topic());
        $this->assertNull($channel->sessionId());
        $this->assertFalse($narrator->wasAnnounced('call-1'));
        $this->assertFalse($budget->exhausted());
        $this->assertFalse($tracker->wasProductive());
        $this->assertFalse($gate->mentions('CT1088'));
        $refusal = $finder->setBuildCode('X');
        $this->assertInstanceOf(ToolError::class, $refusal);
        $this->assertStringStartsWith('No vehicle yet', $refusal->reason, 'the previous turn\'s vehicle must be gone');
        $guard($failure);

        $channel->bindSession('https://localhost/finder/sessions/next-turn');
        $hub = $container->get(RecordingHub::class);
        $hub->updates = [];
        $tools->signalTurnPhase('end');
        $frames = array_map(static fn ($u): string => $u->getData(), $hub->updates);
        $this->assertSame(['{"type":"turn","phase":"end"}'], $frames, 'a previous turn\'s deferred toast must not flush');
    }

    public function testAFailedSettlementLeavesNoSessionBound(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $settlement = $container->get(TurnSettlement::class);
        $turn = new RunAgentTurn('x', self::PREVIOUS_SESSION, owner: self::TEST_OWNER);

        $settlement->open($turn);
        $settlement->fail($turn, 'worker died');

        $this->assertNull($container->get(DashboardChannel::class)->sessionId());
    }
}
