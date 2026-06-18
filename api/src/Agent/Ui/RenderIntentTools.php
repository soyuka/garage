<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Webmozart\Assert\Assert;

/**
 * Agent-local render-intent tools: the LLM calls these to drive the dashboard. Each validates its
 * arguments against the typed {@see UiAction} vocabulary and publishes the resulting intent to the
 * caller's *per-session* Mercure topic, so only the requesting dashboard reacts (domain writes,
 * by contrast, fan out on public vehicle topics via {@see \App\Mercure\DomainPublisher}).
 *
 * These stay `#[AsTool]` (NOT MCP): an MCP tool *call* renders nothing in a host — only a tool
 * *result* carrying a UI resource does. Host-reach therefore comes from the delivery channel, not
 * from the tool kind, which is why the vocabulary is kept transport-agnostic: today it ships over
 * the Mercure session topic; tomorrow the same intents can cross an ext-apps postMessage bridge
 * unchanged. The tools return a tiny ack string to keep tool results out of the LLM context.
 */
#[AsTool('select_vehicle', 'Focus the dashboard on one vehicle, by its @id (the IRI returned by list_vehicles, e.g. /garage/vehicles/01J...).', 'selectVehicle')]
#[AsTool('toast', 'Show a transient toast to the user. tone is one of: info, ok, warn, alarm.', 'toast')]
#[AsTool('show_fact', 'Surface a focused panel showing ONLY the requested fact(s) of the selected vehicle — use it to answer a spec question like "capacité huile moteur" without dumping the whole list. keys are vehicle-fact keys (e.g. oil_capacity, oil_type, spark_plug_ref). Pass a short title for the panel header.', 'showFact')]
#[AsTool('propose_actions', 'Render a call-to-action card: a headline, an optional one-line finding (e.g. "Trouvé en ligne : vidange + courroie"), and a few next-step buttons. Each action has a short label and the instruction sent back when the user clicks it. Use this to offer follow-ups after answering — it replaces ad-hoc suggestion chips.', 'proposeActions')]
final class RenderIntentTools
{
    private ?string $sessionTopic = null;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
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

    public function toast(string $message, string $tone = 'info'): string
    {
        Assert::inArray($tone, ['info', 'ok', 'warn', 'alarm']);
        $this->publish(['type' => 'toast', 'tone' => $tone, 'message' => $message]);

        return 'ok';
    }

    /**
     * @param list<string> $keys vehicle-fact keys to spotlight (e.g. ["oil_capacity", "oil_type"])
     */
    public function showFact(array $keys, ?string $title = null): string
    {
        Assert::allStringNotEmpty($keys);
        $action = ['type' => 'showFact', 'keys' => array_values($keys)];
        if (null !== $title && '' !== trim($title)) {
            $action['title'] = $title;
        }
        $this->publish($action);

        return 'ok';
    }

    /**
     * @param list<array{label: string, instruction: string}> $actions next-step buttons; each click
     *                                                                  re-runs the agent with `instruction`
     */
    public function proposeActions(string $headline, array $actions, ?string $finding = null): string
    {
        Assert::notEmpty($actions);
        $buttons = array_map(static function (array $action): array {
            Assert::keyExists($action, 'label');
            Assert::keyExists($action, 'instruction');
            Assert::stringNotEmpty($action['label']);
            Assert::stringNotEmpty($action['instruction']);

            return ['label' => $action['label'], 'instruction' => $action['instruction']];
        }, $actions);

        $payload = ['type' => 'proposeActions', 'headline' => $headline, 'actions' => $buttons];
        if (null !== $finding && '' !== trim($finding)) {
            $payload['finding'] = $finding;
        }
        $this->publish($payload);

        return 'ok';
    }

    /**
     * @param array<string, mixed> $action a typed UIAction
     */
    private function publish(array $action): void
    {
        Assert::notNull($this->sessionTopic, 'No session topic bound for this turn.');

        // A render intent is best-effort UI, like the domain live-updates in DomainPublisher:
        // a hub that is down (or untrusted TLS) must never turn the tool call into an error the
        // model then sees and tries to "recover" from. Log and move on — the tool still acks ok.
        try {
            $this->hub->publish(new Update($this->sessionTopic, json_encode($action, \JSON_THROW_ON_ERROR)));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for render intent {type} on {topic}: {error}', [
                'type' => $action['type'] ?? '',
                'topic' => $this->sessionTopic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
