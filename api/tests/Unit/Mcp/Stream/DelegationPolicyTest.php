<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Stream;

use App\Mcp\Stream\DelegationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DelegationPolicyTest extends TestCase
{
    private const string SSE = 'application/json, text/event-stream';

    public function testDelegatesAToolCallFromAClientThatAcceptsAStream(): void
    {
        $this->assertTrue((new DelegationPolicy())->shouldDelegate(
            self::request(self::SSE),
            self::body('tools/call', 7),
        ));
    }

    /**
     * initialize carries the session id and the negotiated capabilities on the response itself, so a
     * client handed those over a stream would have nothing to key its next request on.
     */
    #[DataProvider('passThroughMethods')]
    public function testLeavesTheHandshakeAndTheListMethodsAlone(string $method): void
    {
        $this->assertFalse((new DelegationPolicy())->shouldDelegate(
            self::request(self::SSE),
            self::body($method, 1),
        ));
    }

    public static function passThroughMethods(): iterable
    {
        yield 'initialize' => ['initialize'];
        yield 'tools/list' => ['tools/list'];
        yield 'resources/read' => ['resources/read'];
        yield 'prompts/list' => ['prompts/list'];
    }

    public function testLeavesAClientThatWantsPlainJsonAlone(): void
    {
        $this->assertFalse((new DelegationPolicy())->shouldDelegate(
            self::request('application/json'),
            self::body('tools/call', 7),
        ));
    }

    /**
     * A notification is answered with 202 and no body, so there is nothing to stream.
     */
    public function testLeavesANotificationAlone(): void
    {
        $request = self::request(self::SSE);
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'tools/call'], \JSON_THROW_ON_ERROR);

        $this->assertFalse((new DelegationPolicy())->shouldDelegate($request, $body));
    }

    public function testLeavesAGetAlone(): void
    {
        $request = self::request(self::SSE);
        $request->setMethod('GET');

        $this->assertFalse((new DelegationPolicy())->shouldDelegate($request, self::body('tools/call', 7)));
    }

    public function testTheKillSwitchStopsEverything(): void
    {
        $this->assertFalse((new DelegationPolicy(enabled: false))->shouldDelegate(
            self::request(self::SSE),
            self::body('tools/call', 7),
        ));
    }

    public function testLeavesAnUnparseableBodyAlone(): void
    {
        $this->assertFalse((new DelegationPolicy())->shouldDelegate(self::request(self::SSE), 'not json'));
    }

    private static function request(string $accept): Request
    {
        return new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => $accept]);
    }

    private static function body(string $method, int $id): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method], \JSON_THROW_ON_ERROR);
    }
}
