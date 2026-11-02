<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Agent\Ui\StepNarrator;
use App\Agent\Ui\TerminalToolListener;
use App\Agent\Ui\TurnActivityTracker;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The turn guards and the narrator match tools by name, so a name nothing serves is dead weight that
 * no test notices: the finder's tools stayed listed for a week after they moved to bonneref.
 */
final class AgentToolNamesTest extends AppTestCase
{
    use McpClientTrait;

    public function testEveryToolTheTurnGuardsAndTheNarratorNameExists(): void
    {
        $client = static::createClient();
        $sessionId = $this->initialize($client);

        $agentToolbox = static::getContainer()->get('ai.toolbox.garage');

        $known = [
            ...array_column($this->call($client, $sessionId, 'tools/list', [], 2)['result']['tools'], 'name'),
            ...array_map(static fn (Tool $tool): string => $tool->getName(), $agentToolbox->getTools()),
        ];

        $named = [
            'TerminalToolListener::TERMINAL_TOOLS' => TerminalToolListener::TERMINAL_TOOLS,
            'TurnActivityTracker::PRODUCTIVE_TOOLS' => TurnActivityTracker::PRODUCTIVE_TOOLS,
            'StepNarrator::TOOLS' => StepNarrator::TOOLS,
        ];
        foreach ($named as $list => $names) {
            self::assertSame([], array_values(array_diff($names, $known)), $list.' names tools nothing serves');
        }
    }
}
