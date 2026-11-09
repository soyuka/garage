<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @implements RequestHandlerInterface<mixed>
 */
final class ThrowingRequestHandler implements RequestHandlerInterface
{
    public function __construct(private readonly \Throwable $thrown)
    {
    }

    public function supports(Request $request): bool
    {
        return true;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        throw $this->thrown;
    }
}
