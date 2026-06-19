<?php

declare(strict_types=1);

namespace App\Agent\Ui;

/**
 * One part of a {@see ActionButton}'s deterministic write — a line of the shopping list the click
 * merges onto a planned task. Modelled as a class (not an `array{ref, brand}` shape) for the same
 * reason {@see ActionButton} is: TypeInfo degrades an array-shape to "array of strings", so the
 * generated tool schema would hide the keys from the LLM; an object type recurses into a proper
 * `{ref, brand, label, qty}` schema and is denormalised back by ToolCallArgumentResolver.
 */
final readonly class ActionPart
{
    public function __construct(
        public string $ref,
        public ?string $brand = null,
        public ?string $label = null,
        public ?int $qty = null,
    ) {
    }

    /**
     * @return array{ref: string, brand?: string, label?: string, qty?: int}
     */
    public function toArray(): array
    {
        $part = ['ref' => $this->ref];
        if (null !== $this->brand) {
            $part['brand'] = $this->brand;
        }
        if (null !== $this->label) {
            $part['label'] = $this->label;
        }
        if (null !== $this->qty) {
            $part['qty'] = $this->qty;
        }

        return $part;
    }
}
