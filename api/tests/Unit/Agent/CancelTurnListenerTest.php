<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\TurnCancellations;
use App\Agent\Ui\CancelTurnListener;
use App\Agent\Ui\RenderIntentTools;
use App\Tests\Mock\RecordingHub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CancelTurnListenerTest extends TestCase
{
    private const SESSION_TOPIC = 'https://app.test/agent/sessions/sess-abc';

    public function testEndsTheTurnAndToastsWhenCancelWasRequested(): void
    {
        $cancellations = new TurnCancellations(new ArrayAdapter());
        $cancellations->request('sess-abc');

        $hub = new RecordingHub();
        $render = new RenderIntentTools($hub, new NullLogger());
        $render->bindSession(self::SESSION_TOPIC);

        $event = new ToolCallsExecuted([]);
        (new CancelTurnListener($cancellations, $render))($event);

        $this->assertTrue($event->hasResult(), 'a requested cancel must end the tool loop');
        $this->assertSame('', $event->getResult()->getContent());
        $this->assertFalse($cancellations->isRequested('sess-abc'), 'the flag is cleared once honoured');

        $toasts = array_filter($hub->updates, static fn ($u): bool => str_contains($u->getData(), '"toast"'));
        $this->assertNotEmpty($toasts, 'the user is told the turn was interrupted');
    }

    public function testDoesNothingWhenNoCancelRequested(): void
    {
        $cancellations = new TurnCancellations(new ArrayAdapter());

        $render = new RenderIntentTools(new RecordingHub(), new NullLogger());
        $render->bindSession(self::SESSION_TOPIC);

        $event = new ToolCallsExecuted([]);
        (new CancelTurnListener($cancellations, $render))($event);

        $this->assertFalse($event->hasResult(), 'an uncancelled turn keeps looping');
    }

    public function testYieldsToAnAlreadySetResult(): void
    {
        $cancellations = new TurnCancellations(new ArrayAdapter());
        $cancellations->request('sess-abc');

        $render = new RenderIntentTools(new RecordingHub(), new NullLogger());
        $render->bindSession(self::SESSION_TOPIC);

        // A terminal tool (end_turn) already ended the turn this round — do not double-handle.
        $event = new ToolCallsExecuted([]);
        $event->setResult(new \Symfony\AI\Platform\Result\TextResult('done'));
        (new CancelTurnListener($cancellations, $render))($event);

        $this->assertSame('done', $event->getResult()->getContent());
        $this->assertTrue($cancellations->isRequested('sess-abc'), 'the flag is untouched when another listener owns the result');
    }
}
