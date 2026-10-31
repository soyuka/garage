<?php

declare(strict_types=1);

namespace App\Mcp;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use App\Exception\MissingApiKeyException;
use App\Exception\RateLimitExceededException;
use App\Exception\RecordNotFoundException;
use App\Exception\UpstreamException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turns a domain failure into a tool result the model can act on.
 *
 * Without this, every one of them left ApiPlatform's MCP handler uncaught and became a JSON-RPC
 * -32603 carrying the raw exception message. Two consequences, both observed in production on
 * 2026-09-14: the in-app agent never sees the message at all (Symfony AI's
 * ToolExecutionException::getToolCallResult() replaces it with a fixed sentence), so a stalled SIV
 * and an unknown plate are literally the same string to the model — it guessed, and told the user
 * the plate did not exist; and the messages name env vars, which a -32603 ships to any MCP client.
 *
 * Mapped failures therefore come back as a CallToolResult carrying `status` + `retryable`. A miss
 * keeps isError=false, matching the contract the garage tools already keep ({@see ToolResult}).
 * Anything unmapped still throws: an unexpected bug must stay loud.
 *
 * @implements RequestHandlerInterface<mixed>
 */
#[AsDecorator('api_platform.mcp.handler')]
final readonly class DomainErrorHandler implements RequestHandlerInterface
{
    private const MISS = 'not_found';

    /**
     * @param RequestHandlerInterface<mixed> $decorated
     */
    public function __construct(
        #[AutowireDecorated] private RequestHandlerInterface $decorated,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->decorated->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        try {
            return $this->decorated->handle($request, $session);
        } catch (\Throwable $e) {
            // Resources have no CallToolResult to answer with; leave their shape alone.
            if (!$request instanceof CallToolRequest || null === $payload = self::classify($e)) {
                throw $e;
            }

            return new Response($request->getId(), new CallToolResult(
                [new TextContent(json_encode($payload, \JSON_THROW_ON_ERROR))],
                self::MISS !== $payload['status'],
                $payload,
            ));
        }
    }

    /**
     * @return array<string, mixed>|null null = not a domain error, let it bubble
     */
    private static function classify(\Throwable $e): ?array
    {
        return match (true) {
            $e instanceof UpstreamException => $e->transient ? [
                'status' => 'upstream_unavailable',
                'retryable' => true,
                'source' => $e->source,
                'hint' => sprintf('%s did not answer in time. This is temporary and says NOTHING about whether the thing you looked up exists — retry, and never report it to the user as "not found".', $e->source),
            ] : [
                'status' => 'upstream_rejected',
                'retryable' => false,
                'source' => $e->source,
                'hint' => sprintf('%s rejected the request. Retrying will not help; check the arguments.', $e->source),
            ],
            // Messages name env vars — carry only the source.
            $e instanceof MissingApiKeyException => [
                'status' => 'not_configured',
                'retryable' => false,
                'source' => $e->source,
                'hint' => sprintf('%s is not configured on this server. Tell the user this capability is unavailable; do not retry and do not guess an answer.', $e->source),
            ],
            $e instanceof RateLimitExceededException => [
                'status' => 'rate_limited',
                'retryable' => false,
                'retryAfter' => $e->retryAfterSeconds(),
                'hint' => sprintf('This account has used up its plate lookups for now. Tell the user to try again in about %d minutes; do not retry now and do not guess the vehicle.', (int) ceil($e->retryAfterSeconds() / 60)),
            ],
            $e instanceof RecordNotFoundException, $e instanceof NotFoundHttpException, $e instanceof ItemNotFoundException => [
                'status' => self::MISS,
                'retryable' => false,
                'reason' => $e->getMessage(),
                'hint' => 'This is a definitive miss, not a failure: the upstream answered and has no such record.',
            ],
            default => null,
        };
    }
}
