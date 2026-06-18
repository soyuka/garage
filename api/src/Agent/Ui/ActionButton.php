<?php

declare(strict_types=1);

namespace App\Agent\Ui;

/**
 * One next-step button of a {@see RenderIntentTools::proposeActions()} card. Modelled as a class
 * (not a phpstan `array{label, instruction}` shape) so the generated tool schema carries the keys:
 * TypeInfo degrades an array-shape to "array of strings", which leaves the LLM blind to the
 * required fields, whereas an object type is recursed into a proper `{label, instruction}` schema
 * and denormalised back from the tool call by ToolCallArgumentResolver.
 */
final readonly class ActionButton
{
    public function __construct(
        public string $label,
        public string $instruction,
    ) {
    }
}
