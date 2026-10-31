<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Ui\CancelTurnListener;
use App\Agent\Ui\RenderIntentTools;
use App\I18n\LocaleContext;
use App\Tests\Mock\RecordingHub;
use App\Tests\Mock\TestDashboardChannel;
use App\Tests\Support\InMemoryTurnCancellations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Update;

final class CancelTurnListenerTest extends TestCase
{
    private const SESSION_TOPIC = 'https://app.test/agent/sessions/sess-abc';

    public function testEndsTheTurnAndToastsWhenCancelWasRequested(): void
    {
        $cancellations = new InMemoryTurnCancellations();
        $cancellations->request('sess-abc');

        $hub = new RecordingHub();
        $render = new RenderIntentTools($hub, new NullLogger(), TestDashboardChannel::create());
        $render->bindSession(self::SESSION_TOPIC);

        $event = new ToolCallsExecuted([]);
        (new CancelTurnListener($cancellations, $render, new LocaleContext(new RequestStack())))($event);

        $this->assertTrue($event->hasResult(), 'a requested cancel must end the tool loop');
        $this->assertSame('', $event->getResult()->getContent());
        $this->assertFalse($cancellations->isRequested('sess-abc'), 'the flag is cleared once honoured');

        $this->assertEmpty(self::toasts($hub), 'a bound session holds its toast until the turn settles');

        $render->signalTurnPhase('end');
        $this->assertNotEmpty(self::toasts($hub), 'the user is told the turn was interrupted');
    }

    public function testDoesNothingWhenNoCancelRequested(): void
    {
        $cancellations = new InMemoryTurnCancellations();

        $render = new RenderIntentTools(new RecordingHub(), new NullLogger(), TestDashboardChannel::create());
        $render->bindSession(self::SESSION_TOPIC);

        $event = new ToolCallsExecuted([]);
        (new CancelTurnListener($cancellations, $render, new LocaleContext(new RequestStack())))($event);

        $this->assertFalse($event->hasResult(), 'an uncancelled turn keeps looping');
    }

    public function testYieldsToAnAlreadySetResult(): void
    {
        $cancellations = new InMemoryTurnCancellations();
        $cancellations->request('sess-abc');

        $render = new RenderIntentTools(new RecordingHub(), new NullLogger(), TestDashboardChannel::create());
        $render->bindSession(self::SESSION_TOPIC);

        // A terminal tool (end_turn) already ended the turn this round — do not double-handle.
        $event = new ToolCallsExecuted([]);
        $event->setResult(new TextResult('done'));
        (new CancelTurnListener($cancellations, $render, new LocaleContext(new RequestStack())))($event);

        $this->assertSame('done', $event->getResult()->getContent());
        $this->assertTrue($cancellations->isRequested('sess-abc'), 'the flag is untouched when another listener owns the result');
    }

    /**
     * @phpstan-impure
     *
     * @return array<Update>
     */
    private static function toasts(RecordingHub $hub): array
    {
        return array_filter($hub->updates, static fn (Update $u): bool => str_contains($u->getData(), '"toast"'));
    }
}
