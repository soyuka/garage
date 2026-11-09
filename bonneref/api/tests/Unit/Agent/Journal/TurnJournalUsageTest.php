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

        self::assertInstanceOf(AgentTurn::class, $persisted);
        self::assertSame(8, $persisted->getPromptTokens());
        self::assertSame(12000, $persisted->getCacheReadTokens());
        self::assertSame(3000, $persisted->getCacheCreationTokens());
        self::assertSame(250, $persisted->getCompletionTokens());
        self::assertSame(15258, $persisted->getTotalTokens(), 'the total counts cached input too');
    }

    public function testAWebSearchSideCallIsRecordedApartFromTheTurnsOwnTokens(): void
    {
        $persisted = null;
        $dm = $this->createStub(DocumentManager::class);
        $dm->method('persist')->willReturnCallback(static function (object $turn) use (&$persisted): void {
            $persisted = $turn;
        });

        $journal = new TurnJournal($dm, new NullLogger());
        $journal->begin(new RunAgentTurn('filtre', 'https://localhost/agent/sessions/s1', 'owner'), 'ctx');
        $body = ['model' => 'claude-haiku-4-5-20251001', 'usage' => ['input_tokens' => 9000, 'output_tokens' => 400, 'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 0, 'server_tool_use' => ['web_search_requests' => 2]]];
        $journal->recordWebSearchResponse('anthropic', 'q1', $body, 'claude-haiku-4-5?max_tokens=2048');
        $journal->recordWebSearchResponse('anthropic', 'q2', $body, 'claude-haiku-4-5?max_tokens=2048');
        $journal->recordWebSearchResponse('gemini', 'q3', ['usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 7, 'thoughtsTokenCount' => 3]], 'gemini-2.5-flash');
        $journal->finish(AgentTurnStatus::Completed, null);

        self::assertInstanceOf(AgentTurn::class, $persisted);
        self::assertNull($persisted->getPromptTokens(), 'the side call is not the orchestrator\'s usage');
        self::assertSame([
            'claude-haiku-4-5' => ['promptTokens' => 18000, 'completionTokens' => 800, 'cacheReadTokens' => 200, 'cacheCreationTokens' => 0],
            'gemini-2.5-flash' => ['promptTokens' => 50, 'completionTokens' => 10, 'cacheReadTokens' => 0, 'cacheCreationTokens' => 0],
        ], $persisted->getSideCallUsage());
        self::assertSame(4, $persisted->getWebSearchRequests());
    }

    private function deltaOf(TokenUsage $usage): DeltaEvent
    {
        $empty = (static function (): \Generator { yield from []; })();

        return new DeltaEvent(new StreamResult($empty), $usage);
    }
}
