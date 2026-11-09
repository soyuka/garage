<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\TraceableAgent;

final class AgentMaxToolCallsTest extends AppTestCase
{
    #[DataProvider('agents')]
    public function testTheToolCallLoopIsCappedAt25(string $name): void
    {
        static::createClient();
        $agent = static::getContainer()->get('ai.agent.'.$name);

        if ($agent instanceof TraceableAgent) {
            $agent = (new \ReflectionProperty(TraceableAgent::class, 'agent'))->getValue($agent);
        }
        $this->assertInstanceOf(Agent::class, $agent);
        $runner = (new \ReflectionProperty(Agent::class, 'runner'))->getValue($agent);
        $this->assertSame(25, (new \ReflectionProperty($runner, 'maxToolCalls'))->getValue($runner));
    }

    /** @return iterable<string, array{string}> */
    public static function agents(): iterable
    {
        yield 'garage' => ['garage'];
    }
}
