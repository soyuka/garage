<?php

declare(strict_types=1);

namespace App\State\Dashboard;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Ui\ActionButton;
use App\Agent\Ui\ActionPart;
use App\Agent\Ui\RenderIntentTools;
use App\Dto\Dashboard\ProposeActionInput;
use App\Dto\Dashboard\ProposeActionsInput;
use App\Dto\Dashboard\ProposePartInput;
use App\Garage\ToolResult;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP `propose_actions`: the call-to-action card. The buttons are mapped onto the SAME
 * {@see ActionButton}/{@see ActionPart} value objects the agent tool uses, so the two drivers cannot
 * drift apart on the wire shape (an NL button re-runs the in-app agent; a deterministic button
 * PATCHes parts onto a task with no turn at all).
 *
 * The nested input is read as arrays: the tool's JSON Schema is generated from the typed
 * {@see ProposeActionInput}/{@see ProposePartInput} DTOs (so the host sees every key), but the MCP
 * deserialisation only hydrates the TOP-level input object — a nested list arrives as plain arrays.
 * Both shapes are accepted so a future framework version that does hydrate them keeps working.
 *
 * @implements ProcessorInterface<ProposeActionsInput, CallToolResult>
 */
final readonly class ProposeActionsProcessor implements ProcessorInterface
{
    public function __construct(private RenderIntentTools $render)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        if ('' === trim($data->headline) || [] === $data->actions) {
            return ToolResult::error('invalid_input', 'headline and at least one action are required.');
        }

        $buttons = [];
        foreach ($data->actions as $action) {
            $label = (string) (self::field($action, 'label') ?? '');
            $instruction = self::text($action, 'instruction');
            $taskId = self::text($action, 'taskId');

            if ('' === trim($label)) {
                return ToolResult::error('invalid_input', 'every action needs a label.');
            }
            if (null === $instruction && null === $taskId) {
                return ToolResult::error('invalid_input', 'an action is EITHER an NL follow-up (instruction) OR a deterministic write (taskId + parts).');
            }

            $buttons[] = new ActionButton($label, $instruction, $taskId, self::parts($action));
        }

        $refused = $this->render->tryProposeActions($data->headline, $buttons, $data->finding);
        if (null !== $refused) {
            return $refused;
        }

        return ToolResult::of(['status' => 'rendered', 'intent' => 'proposeActions']);
    }

    /**
     * @param ProposeActionInput|array<string, mixed> $action
     *
     * @return list<ActionPart>
     */
    private static function parts(ProposeActionInput|array $action): array
    {
        $parts = [];
        $actionParts = self::field($action, 'parts');
        foreach (\is_array($actionParts) ? $actionParts : [] as $part) {
            if (!$part instanceof ProposePartInput && !\is_array($part)) {
                continue;
            }
            $ref = (string) (self::field($part, 'ref') ?? '');
            if ('' === trim($ref)) {
                continue;
            }
            $qty = self::field($part, 'qty');
            $parts[] = new ActionPart($ref, self::text($part, 'brand'), self::text($part, 'label'), null === $qty ? null : (int) $qty);
        }

        return $parts;
    }

    /** @param ProposeActionInput|ProposePartInput|array<array-key, mixed> $source */
    private static function text(ProposeActionInput|ProposePartInput|array $source, string $field): ?string
    {
        $value = self::field($source, $field);

        return null === $value || '' === trim((string) $value) ? null : (string) $value;
    }

    /** @param ProposeActionInput|ProposePartInput|array<array-key, mixed> $source */
    private static function field(ProposeActionInput|ProposePartInput|array $source, string $field): mixed
    {
        return \is_array($source) ? ($source[$field] ?? null) : (get_object_vars($source)[$field] ?? null);
    }
}
