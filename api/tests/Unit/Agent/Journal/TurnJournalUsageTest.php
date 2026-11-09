<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Journal;

use App\Agent\Journal\TurnJournal;
use App\Agent\WebSearchBudget;
use App\Agent\WebSearchBudgetStreamListener;
use App\Entity\AgentTurn;
use App\Entity\AgentTurnStatus;
use App\Message\RunAgentTurn;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

final class TurnJournalUsageTest extends TestCase
{
    public function testStreamedCacheTokensReachTheJournal(): void
    {
        $persisted = null;
        $dm = $this->createStub(DocumentManager::class);
        $dm->method('persist')->willReturnCallback(static function (object $turn) use (&$persisted): void {
            $persisted = $turn;
        });

        $journal = new TurnJournal($dm, new NullLogger());
        $journal->begin(new RunAgentTurn('filtre', 'https://localhost/agent/sessions/s1', 'owner'), 'ctx');

        $listener = new WebSearchBudgetStreamListener(new WebSearchBudget(maxPerTurn: 1), journal: $journal);
        $listener->onDelta($this->deltaOf(new TokenUsage(promptTokens: 8, cacheCreationTokens: 3000, cacheReadTokens: 12000)));
        $listener->onDelta($this->deltaOf(new TokenUsage(completionTokens: 250)));

        $journal->finish(AgentTurnStatus::Completed, null);

        $this->assertInstanceOf(AgentTurn::class, $persisted);
        $this->assertSame(8, $persisted->getPromptTokens());
        $this->assertSame(12000, $persisted->getCacheReadTokens());
        $this->assertSame(3000, $persisted->getCacheCreationTokens());
        $this->assertSame(250, $persisted->getCompletionTokens());
        $this->assertSame(15258, $persisted->getTotalTokens(), 'the total counts cached input too');
    }

    private function deltaOf(TokenUsage $usage): DeltaEvent
    {
        $empty = (static function (): \Generator { yield from []; })();

        return new DeltaEvent(new StreamResult($empty), $usage);
    }
}
