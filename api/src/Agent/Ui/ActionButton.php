<?php

declare(strict_types=1);

namespace App\Agent\Ui;

/**
 * One next-step button of a {@see RenderIntentTools::proposeActions()} card. Modelled as a class
 * (not a phpstan `array{label, instruction}` shape) so the generated tool schema carries the keys:
 * TypeInfo degrades an array-shape to "array of strings", which leaves the LLM blind to the
 * required fields, whereas an object type is recursed into a proper schema and denormalised back
 * from the tool call by ToolCallArgumentResolver.
 *
 * A button is one of two kinds — enforced in {@see RenderIntentTools::proposeActions()}:
 *  - NL follow-up: `instruction` re-runs the agent with that text (the same path a typed message
 *    takes). Use it when the next step genuinely needs the model.
 *  - Deterministic write: `taskId` + `parts` merges those parts onto an existing checklist task via
 *    a plain PATCH — NO agent turn. Use it to add a part reference the user picked; the dashboard's
 *    shopping-list block then re-renders from the live task.
 */
final readonly class ActionButton
{
    /**
     * @param list<ActionPart> $parts parts merged onto $taskId when the button is a deterministic write
     */
    public function __construct(
        public string $label,
        public ?string $instruction = null,
        public ?string $taskId = null,
        public array $parts = [],
    ) {
    }
}
