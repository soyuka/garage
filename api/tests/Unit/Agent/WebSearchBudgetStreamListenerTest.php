<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\I18n\LocaleContext;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Agent\Ui\RenderIntentTools;
use App\Tests\Mock\TestDashboardChannel;
use App\Agent\Ui\StepNarrator;
use App\Agent\WebSearchBudget;
use App\Agent\WebSearchBudgetStreamListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class WebSearchBudgetStreamListenerTest extends TestCase
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $published
     */
    private function listener(WebSearchBudget $budget, \ArrayObject $published): WebSearchBudgetStreamListener
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });
        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        return new WebSearchBudgetStreamListener($budget, new StepNarrator($tools, new LocaleContext(new RequestStack())));
    }

    private function deltaOf(TokenUsage $usage): DeltaEvent
    {
        $empty = (static function (): \Generator { yield from []; })();

        return new DeltaEvent(new StreamResult($empty), $usage);
    }

    public function testRecordsTheBudgetAndNarratesWhenASearchRan(): void
    {
        $budget = new WebSearchBudget(maxPerTurn: 1);
        $published = new \ArrayObject();

        $this->listener($budget, $published)->onDelta($this->deltaOf(new TokenUsage(toolTokens: 1)));

        $this->assertTrue($budget->exhausted());
        $this->assertSame([['type' => 'status', 'message' => 'Je cherche en ligne…', 'tool' => 'web_search']], $published->getArrayCopy());
    }

    public function testDoesNotNarrateWhenNoSearchRan(): void
    {
        $budget = new WebSearchBudget(maxPerTurn: 1);
        $published = new \ArrayObject();

        // A plain prompt/completion delta carries no toolTokens — no search, so no narration.
        $this->listener($budget, $published)->onDelta($this->deltaOf(new TokenUsage(completionTokens: 42)));

        $this->assertFalse($budget->exhausted());
        $this->assertSame([], $published->getArrayCopy());
    }
}
