<?php

declare(strict_types=1);

namespace App\Agent\Ui;

/**
 * One already-formatted line of a {@see RenderIntentTools::showFact()} PROJECTION — the way a FETCHED
 * surface (fluid capacities, oil viscosity, the SIV carte grise) reaches the spec panel. Those values
 * live nowhere on the vehicle, so the `keys` filter over stored facts can never select them: without
 * nodes, `show_fact(["oil_capacity"])` after a successful get_fill_volumes renders an EMPTY panel.
 *
 * Modelled as a class (not an `array{key, label}` shape) for the same reason {@see ActionPart} is:
 * TypeInfo degrades an array-shape to "array of strings", hiding the keys from the LLM, whereas an
 * object type is recursed into a proper schema and denormalised back by ToolCallArgumentResolver.
 *
 * `value` is display-ready — the tool formats it (unit, decimal comma) because the dashboard renders
 * it verbatim. `verified` marks a value read off a catalog/official source rather than inferred.
 */
final readonly class SpecNode
{
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public bool $verified = false,
        public ?string $info = null,
    ) {
    }

    /**
     * @return array{key: string, label: string, value: string, verified: bool, info?: string}
     */
    public function toArray(): array
    {
        $node = [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'verified' => $this->verified,
        ];
        if (null !== $this->info) {
            $node['info'] = $this->info;
        }

        return $node;
    }
}
