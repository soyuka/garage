<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Ui;

use App\Agent\Ui\RenderIntentTools;
use App\Tests\Mock\TestDashboardChannel;
use App\Agent\Ui\StepNarrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class StepNarratorTest extends TestCase
{
    /**
     * @param \ArrayObject<int, array<string, mixed>> $published filled with each published frame
     */
    private function narrator(\ArrayObject $published): StepNarrator
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use ($published): string {
            $published->append(json_decode($update->getData(), true));

            return 'id';
        });

        $tools = new RenderIntentTools($hub, $this->createStub(LoggerInterface::class), TestDashboardChannel::create());
        $tools->bindSession('https://localhost/agent/sessions/abc');

        return new StepNarrator($tools);
    }

    public function testNarratesAKnownGarageToolWithItsFrenchSentence(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('get_service_parts');

        $this->assertSame(
            [['type' => 'status', 'message' => 'Je consulte le catalogue de pièces…']],
            $published->getArrayCopy(),
        );
    }

    public function testStaysSilentForAnUnmappedTool(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('some_tool_with_no_sentence');

        $this->assertSame([], $published->getArrayCopy());
    }

    public function testNarratesTheWebSearchStep(): void
    {
        $published = new \ArrayObject();
        $this->narrator($published)->forTool('web_search');

        $this->assertSame([['type' => 'status', 'message' => 'Je cherche en ligne…']], $published->getArrayCopy());
    }
}
