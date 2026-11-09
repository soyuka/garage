<?php

declare(strict_types=1);

namespace App\Mcp;

use ApiPlatform\Metadata\Exception\ItemNotFoundException;
use App\Exception\NotConfiguredException;
use App\Exception\RecordNotFoundException;
use App\Exception\UpstreamException;
use App\Garage\ToolError;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
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
 * Mapped failures therefore come back as a {@see ToolError}: isError, the status token first, then what
 * to do about it (retry or not). A miss is an error too — the call addressed something that is not there.
 * Anything unmapped still throws: an unexpected bug must stay loud.
 *
 * @implements RequestHandlerInterface<mixed>
 */
#[AsDecorator('api_platform.mcp.handler')]
final readonly class DomainErrorHandler implements RequestHandlerInterface
{
    /** @param RequestHandlerInterface<mixed> $decorated */
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
            $response = $this->decorated->handle($request, $session);
        } catch (\Throwable $e) {
            // Resources have no CallToolResult to answer with; leave their shape alone.
            if (!$request instanceof CallToolRequest || null === $error = self::classify($e)) {
                throw $e;
            }

            return new Response($request->getId(), $error);
        }

        // Arguments failing the input schema are a tool execution error the model can fix (MCP
        // 2026-07-28), not a protocol error: API Platform answers -32602, we answer isError.
        if ($request instanceof CallToolRequest && $response instanceof Error && Error::INVALID_PARAMS === $response->code) {
            return new Response($request->getId(), new ToolError('invalid_input', rtrim($response->message, '.').'. Fix these arguments and call again.'));
        }

        return $response;
    }

    /**
     * null = not a domain error, let it bubble.
     */
    private static function classify(\Throwable $e): ?ToolError
    {
        return match (true) {
            $e instanceof UpstreamException => $e->transient
                ? new ToolError('upstream_unavailable', \sprintf('%s did not answer in time. This is temporary and says NOTHING about whether the thing you looked up exists — retry, and never report it to the user as "not found".', $e->source))
                : new ToolError('upstream_rejected', \sprintf('%s rejected the request. Retrying will not help; check the arguments.', $e->source)),
            $e instanceof NotConfiguredException => new ToolError(
                'not_configured',
                $e->getMessage().'. This capability is unavailable on this deployment. Tell the user so; do not retry and do not guess an answer.',
            ),
            $e instanceof RecordNotFoundException, $e instanceof NotFoundHttpException, $e instanceof ItemNotFoundException => new ToolError(
                'not_found',
                $e->getMessage().' This is a definitive miss, not a failure: the upstream answered and has no such record. Do not retry with the same arguments.',
            ),
            default => null,
        };
    }
}
