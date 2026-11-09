<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;

/**
 * Test platform double that always answers as a STREAM, mirroring how the real Anthropic platform
 * behaves once the worker passes `stream: true` ({@see \App\MessageHandler\RunAgentTurnHandler}).
 * {@see \Symfony\AI\Platform\Test\InMemoryPlatform} ignores the stream option and hands back a plain
 * result, which the profiler's TraceablePlatform rejects (it calls asStream() and demands a
 * StreamResult) — so tests of the streaming turn must use this instead.
 *
 * The scripted closure returns the same shapes as with InMemoryPlatform — a {@see ToolCallResult}
 * to drive the tool loop or a string/{@see TextResult} to finish — and they are wrapped into the
 * matching stream deltas. A closure may also return a {@see StreamResult} directly for full control
 * over the delta sequence (e.g. emitting TokenUsage deltas).
 *
 * @phpstan-type Script \Closure(Model|string, mixed, array<string, mixed>): (ResultInterface|string)
 */
final class ScriptedStreamPlatform implements PlatformInterface
{
    private readonly ModelCatalogInterface $modelCatalog;

    /**
     * @param \Closure(Model|string, mixed, array<string, mixed>): (ResultInterface|string) $script
     */
    public function __construct(private readonly \Closure $script)
    {
        $this->modelCatalog = new FallbackModelCatalog();
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $scripted = ($this->script)($model, $input, $options);

        $stream = $scripted instanceof StreamResult
            ? $scripted
            : new StreamResult($this->toDeltas($scripted));

        return new DeferredResult(new PlainConverter($stream), new InMemoryRawResult(), $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->modelCatalog;
    }

    private function toDeltas(ResultInterface|string $result): \Generator
    {
        if ($result instanceof ToolCallResult) {
            yield new ToolCallComplete($result->getContent());

            return;
        }

        if ($result instanceof TextResult) {
            $result = $result->getContent();
        }
        if (!\is_string($result)) {
            throw new \LogicException(\sprintf('A scripted stream cannot replay a %s.', $result::class));
        }

        yield new TextDelta($result);
    }
}
