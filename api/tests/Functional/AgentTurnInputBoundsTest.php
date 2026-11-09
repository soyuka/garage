<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\ScriptedStreamPlatform;
use PHPUnit\Framework\Attributes\DataProvider;

final class AgentTurnInputBoundsTest extends AppTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function oversized(): iterable
    {
        yield 'instruction' => [['instruction' => str_repeat('a', 2001)]];
        yield 'summary' => [['summary' => str_repeat('a', 501)]];
        yield 'pendingContext' => [['pendingContext' => str_repeat('a', 2001)]];
        yield 'selectedId' => [['selectedId' => '/garage/vehicles/'.str_repeat('a', 200)]];
        yield 'blank instruction' => [['instruction' => '   ']];
        yield 'not a string' => [['instruction' => ['a']]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('oversized')]
    public function testAnOutOfBoundsFieldIsRefusedBeforeTheBudget(array $override): void
    {
        $client = static::createClient();
        $calls = $this->countModelCalls();

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => $override + ['instruction' => 'Bonjour', 'sessionId' => 'sess-bounds'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, $calls->count());
        $this->assertTrue(static::getContainer()->get('limiter.agent_turn')->create(self::TEST_OWNER)->consume(30)->isAccepted(), 'a refused request spends no turn');
    }

    public function testATurnAtTheBoundsIsAccepted(): void
    {
        $client = static::createClient();
        $calls = $this->countModelCalls();

        $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => [
                'instruction' => '  '.str_repeat('a', 2000).'  ',
                'sessionId' => 'sess-bounds',
                'summary' => str_repeat('s', 500),
                'pendingContext' => str_repeat('p', 2000),
                'selectedId' => null,
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $this->assertSame(1, $calls->count());
    }

    /** @return \ArrayObject<int, true> */
    private function countModelCalls(): \ArrayObject
    {
        $calls = new \ArrayObject();
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function () use ($calls): string {
            $calls->append(true);

            return 'Fait.';
        }));

        return $calls;
    }
}
