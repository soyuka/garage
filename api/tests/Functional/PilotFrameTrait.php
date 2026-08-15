<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\RecordingHub;
use Symfony\Component\Mercure\Update;

/**
 * Reads back the render intents an MCP tool published on the owner's PILOT topic — the assertion any
 * test of a dashboard-driving tool needs. Note the kernel reboots between requests by default, which
 * drops every frame but the last call's: a test spanning several tool calls must `disableReboot()`.
 */
trait PilotFrameTrait
{
    /**
     * @return list<array<string, mixed>>
     */
    private function pilotFrames(): array
    {
        $hub = static::getContainer()->get(RecordingHub::class);

        return array_values(array_map(
            static fn (Update $u): array => json_decode($u->getData(), true),
            array_filter($hub->updates, static fn (Update $u): bool => str_contains($u->getTopics()[0] ?? '', '/agent/pilots/')),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastPilotFrame(): ?array
    {
        $frames = $this->pilotFrames();

        return [] === $frames ? null : $frames[array_key_last($frames)];
    }
}
