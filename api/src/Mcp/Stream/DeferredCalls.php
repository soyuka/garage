<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs a delegated call after its lifecycle response has been sent.
 *
 * Caddy gives the "ready" callback about three seconds before it gives up on the stream, which is
 * less than a catalog lookup can take. Answering first and working on terminate keeps that budget
 * for what it is meant to cover — the acknowledgement — rather than the tool.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'runDeferred')]
final class DeferredCalls
{
    /** @var list<string> */
    private array $topics = [];

    public function __construct(private readonly McpStreamRunner $runner, private readonly PendingCallStore $pending)
    {
    }

    public function defer(string $topic): void
    {
        $this->topics[] = $topic;
    }

    public function runDeferred(TerminateEvent $event): void
    {
        $topics = $this->topics;
        $this->topics = [];

        foreach ($topics as $topic) {
            if (null !== $call = $this->pending->take($topic)) {
                $this->runner->run($topic, $call);
            }
        }
    }
}
