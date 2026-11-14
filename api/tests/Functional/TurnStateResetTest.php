<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Attachment\TurnAttachmentScope;
use App\Agent\Journal\TurnJournal;
use App\Agent\SelectedVehicleContext;
use App\Agent\TurnCancellations;
use App\Agent\TurnSettlement;
use App\Agent\Ui\DashboardChannel;
use App\Agent\Ui\RenderIntentTools;
use App\Agent\Ui\StepNarrator;
use App\Agent\Ui\TurnActivityTracker;
use App\Agent\WebSearchBudget;
use App\Bonneref\BonnerefToken;
use App\I18n\Locale;
use App\I18n\LocaleContext;
use App\Message\RunAgentTurn;
use App\Message\TurnAttachmentRef;
use App\MessageHandler\RunAgentTurnHandler;
use App\Security\OwnerContext;
use App\Tests\Mock\RecordingHub;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\ServicesResetter;

final class TurnStateResetTest extends AppTestCase
{
    private const string FOREIGN_OWNER = 'previous-turn@example.com';

    public function testKernelResetUnbindsEveryPerTurnHolder(): void
    {
        static::bootKernel();
        $container = static::getContainer();

        $owner = $container->get(OwnerContext::class);
        $channel = $container->get(DashboardChannel::class);
        $locale = $container->get(LocaleContext::class);
        $selected = $container->get(SelectedVehicleContext::class);
        $tools = $container->get(RenderIntentTools::class);
        $narrator = $container->get(StepNarrator::class);
        $budget = $container->get(WebSearchBudget::class);
        $tracker = $container->get(TurnActivityTracker::class);
        $journal = $container->get(TurnJournal::class);

        $owner->set(self::FOREIGN_OWNER);
        $sessionTopic = $channel->sessionTopic(self::FOREIGN_OWNER, 'sess-1');
        $channel->bindSession($sessionTopic);
        $locale->set(Locale::En);
        $selected->set('/garage/vehicles/01JFOREIGN');
        $tools->toast('pending', 'info');
        $narrator->announce('call-1', 'list_vehicles');
        $budget->record(99);
        $tracker(new ToolCallsExecuted([new ToolResult(new ToolCall('call-2', 'end_turn', []), 'ok')]));
        $journal->begin(new RunAgentTurn('x', $sessionTopic, owner: self::FOREIGN_OWNER), '');

        self::serviceAs('services_resetter', ServicesResetter::class)->reset();

        $this->assertNull($channel->sessionId());
        $this->assertSame($channel->pilotTopic(self::TEST_OWNER), $channel->topic(), 'the fallback owner, not the previous turn\'s');
        $this->assertSame(self::TEST_OWNER, $owner->current());
        $this->assertSame(Locale::DEFAULT, $locale->current());
        $this->assertNull($selected->current());
        $this->assertFalse($narrator->wasAnnounced('call-1'));
        $this->assertFalse($budget->exhausted());
        $this->assertFalse($tracker->wasProductive());
        $this->assertNull($journal->currentTurnId());

        $hub = $container->get(RecordingHub::class);
        $hub->updates = [];
        $tools->signalTurnPhase('end');
        $frames = array_map(static fn ($u): string => $u->getData(), $hub->updatesForTopic($channel->pilotTopic(self::TEST_OWNER)));
        $this->assertSame(['{"type":"turn","phase":"end"}'], $frames, 'a previous turn\'s deferred toast must not flush');
    }

    public function testResetLeavesTheSettlementClosed(): void
    {
        static::bootKernel();
        $settlement = static::getContainer()->get(TurnSettlement::class);
        $hub = static::getContainer()->get(RecordingHub::class);

        $settlement->open(new RunAgentTurn('x', 'https://localhost/agent/sessions/abc', owner: self::FOREIGN_OWNER));
        self::serviceAs('services_resetter', ServicesResetter::class)->reset();
        $hub->updates = [];
        $settlement->onShutdown(null);

        $this->assertSame([], $hub->updates);
    }

    public function testNoBindingOutlivesATurnThatFailsBeforeTheAgentRuns(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $container->set(TurnCancellations::class, new class implements TurnCancellations {
            public function request(string $sessionId): void
            {
            }

            public function isRequested(string $sessionId): bool
            {
                return false;
            }

            public function clear(string $sessionId): void
            {
                throw new \RuntimeException('store down');
            }
        });

        try {
            $container->get(RunAgentTurnHandler::class)(new RunAgentTurn(
                'x',
                'https://localhost/agent/sessions/sess-early-failure',
                owner: self::FOREIGN_OWNER,
                selectedId: '/garage/vehicles/01JFOREIGN',
                locale: Locale::Fr->value,
                accessToken: bin2hex(random_bytes(8)),
                attachments: [new TurnAttachmentRef('01JATTACH', 'bill.pdf', 'application/pdf', 10)],
            ));
            $this->fail('the store failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('store down', $e->getMessage());
        }

        $this->assertNotSame(self::FOREIGN_OWNER, $container->get(OwnerContext::class)->current());
        $this->assertNull($container->get(DashboardChannel::class)->sessionId());
        $this->assertSame(Locale::DEFAULT, $container->get(LocaleContext::class)->current());
        $this->assertNull($container->get(SelectedVehicleContext::class)->current());
        $this->assertNull($container->get(BonnerefToken::class)->get());
        $this->assertFalse($container->get(TurnAttachmentScope::class)->has('01JATTACH'));
    }

    public function testAFailedSettlementLeavesNoSessionBound(): void
    {
        static::bootKernel();
        $container = static::getContainer();
        $settlement = $container->get(TurnSettlement::class);
        $turn = new RunAgentTurn('x', 'https://localhost/agent/sessions/sess-settle', owner: self::FOREIGN_OWNER);

        $settlement->open($turn);
        $settlement->fail($turn, 'worker died');

        $this->assertNull($container->get(DashboardChannel::class)->sessionId());
    }
}
