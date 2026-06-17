<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Webmozart\Assert\Assert;

/**
 * Agent-local render-intent tools: the LLM calls these to drive the dashboard. Each validates its
 * arguments against the typed {@see UiAction} vocabulary and publishes the resulting intent to the
 * caller's *per-session* Mercure topic, so only the requesting dashboard reacts (domain writes,
 * by contrast, fan out on public vehicle topics via {@see \App\Mercure\DomainEventPublisher}).
 *
 * These stay `#[AsTool]` (NOT MCP): an MCP tool *call* renders nothing in a host — only a tool
 * *result* carrying a UI resource does. Host-reach therefore comes from the delivery channel, not
 * from the tool kind, which is why the vocabulary is kept transport-agnostic: today it ships over
 * the Mercure session topic; tomorrow the same intents can cross an ext-apps postMessage bridge
 * unchanged. The tools return a tiny ack string to keep tool results out of the LLM context.
 */
#[AsTool('select_vehicle', 'Focus the dashboard on one vehicle, by its @id (the IRI returned by list_vehicles, e.g. /garage/vehicles/01J...).', 'selectVehicle')]
#[AsTool('suggest', 'Replace the suggestion chips under the composer with a fresh, context-relevant set (short labels).', 'suggest')]
#[AsTool('toast', 'Show a transient toast to the user. tone is one of: info, ok, warn, alarm.', 'toast')]
#[AsTool('patch_panel', 'Patch one panel of the currently selected vehicle. panel is one of: nextService, health, cost.', 'patchPanel')]
final class RenderIntentTools
{
    private ?string $sessionTopic = null;

    public function __construct(private readonly HubInterface $hub)
    {
    }

    /**
     * Binds the per-turn session topic. Called by {@see \App\Controller\AgentTurnController} before
     * the agent runs; a turn is one synchronous request, so there is no cross-turn bleed.
     */
    public function bindSession(string $sessionTopic): void
    {
        $this->sessionTopic = $sessionTopic;
    }

    public function selectVehicle(string $id): string
    {
        $this->publish(['type' => 'selectVehicle', 'id' => $id]);

        return 'ok';
    }

    /**
     * @param list<string> $chips short suggestion labels shown under the composer
     */
    public function suggest(array $chips): string
    {
        Assert::allString($chips);
        $this->publish(['type' => 'renderSuggestions', 'chips' => array_map(static fn (string $label) => ['label' => $label], $chips)]);

        return 'ok';
    }

    public function toast(string $message, string $tone = 'info'): string
    {
        Assert::inArray($tone, ['info', 'ok', 'warn', 'alarm']);
        $this->publish(['type' => 'toast', 'tone' => $tone, 'message' => $message]);

        return 'ok';
    }

    /**
     * @param array<string, mixed> $data the panel payload, e.g. {"value": 1200, "unit": "km"} for nextService
     */
    public function patchPanel(string $panel, array $data): string
    {
        Assert::inArray($panel, ['nextService', 'health', 'cost']);
        $this->publish(['type' => 'patchPanel', 'panel' => $panel, 'data' => $data]);

        return 'ok';
    }

    /**
     * @param array<string, mixed> $action a typed UIAction
     */
    private function publish(array $action): void
    {
        Assert::notNull($this->sessionTopic, 'No session topic bound for this turn.');

        $this->hub->publish(new Update($this->sessionTopic, json_encode($action, \JSON_THROW_ON_ERROR)));
    }
}
