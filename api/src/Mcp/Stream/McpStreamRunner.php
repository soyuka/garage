<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

use App\Security\OwnerContext;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;

/**
 * Runs a delegated tool call and publishes its answer to the stream's topic.
 *
 * The call is replayed through the same {@see Server} the endpoint uses, so a delegated answer and a
 * synchronous one come from one code path. {@see StreamableHttpTransport::createJsonResponse()}
 * returns every outgoing message on the response body, batched into a JSON array when there is more
 * than one; only a suspended fiber takes the branch that echoes
 * ({@see StreamableHttpTransport::flushOutgoingMessages()}), which is captured when it can be.
 */
final readonly class McpStreamRunner
{
    public function __construct(
        private Server $server,
        private ServerRequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private ResponseFactoryInterface $responses,
        private MiddlewareFactory $middleware,
        private StreamPublisher $publisher,
        private OwnerContext $owner,
        private string $path,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function run(string $topic, PendingCall $call): void
    {
        // The lifecycle callback carries no bearer, so tenancy comes from the held call.
        $this->owner->set($call->owner);

        try {
            $this->publishAnswer($topic, $call);
        } catch (\Throwable $e) {
            $this->logger?->error('Delegated MCP call failed.', ['topic' => $topic, 'exception' => $e]);
            $this->publisher->message($topic, $this->internalError($call->body, $e));
        } finally {
            // Always close the stream: without the terminal event the client waits out the timeout.
            $this->publisher->end($topic);
            $this->owner->set(null);
        }
    }

    private function publishAnswer(string $topic, PendingCall $call): void
    {
        $request = $this->requests
            ->createServerRequest('POST', $this->path)
            ->withBody($this->streams->createStream($call->body));

        foreach ($call->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $transport = new StreamableHttpTransport(
            $request,
            $this->responses,
            $this->streams,
            logger: $this->logger,
            middleware: $this->middleware->create(),
        );

        // A delegated call runs on kernel.terminate, after the response has been sent, and PHP then
        // refuses a new output buffer ("ob_start(): Failed to create buffer"). Capturing is therefore
        // best-effort: the SDK only echoes when a fiber suspends, and every other answer — including a
        // multi-message one, which it batches into a JSON array — comes back on the response body.
        $depth = ob_get_level();
        $capturing = @ob_start();

        try {
            $response = $this->server->run($transport);
            $returned = (string) $response->getBody();
        } finally {
            $echoed = $capturing && ob_get_level() > $depth ? (string) ob_get_clean() : '';
        }

        if ('text/event-stream' === strtolower(explode(';', $response->getHeaderLine('Content-Type'))[0])) {
            foreach (SseFrames::data($echoed.$returned) as $frame) {
                $this->publisher->message($topic, $frame);
            }

            return;
        }

        if ('' !== $returned) {
            $this->publisher->message($topic, $returned);
        }
    }

    /**
     * Mirrors the request id so the client can settle the call it is waiting on rather than hanging.
     */
    private function internalError(string $body, \Throwable $e): string
    {
        $id = json_decode($body, true)['id'] ?? null;

        return json_encode([
            'jsonrpc' => '2.0',
            'id' => \is_string($id) || \is_int($id) ? $id : null,
            'error' => ['code' => -32603, 'message' => $e->getMessage()],
        ], \JSON_THROW_ON_ERROR);
    }
}
