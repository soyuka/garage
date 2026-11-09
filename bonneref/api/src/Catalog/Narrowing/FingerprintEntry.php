<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

final readonly class FingerprintEntry
{
    private const MM_FLOOR = 2.0;
    private const MM_RATIO = 0.025;

    /**
     * @param ?float $min  lowest value among the parts merged into this entry (the value itself for one part)
     * @param ?float $max  highest value, likewise
     * @param bool   $info displayed but never used to split or tell parts apart: suppliers disagree on it
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public ?float $number,
        public ?string $unit,
        public ?float $min = null,
        public ?float $max = null,
        public bool $info = false,
    ) {
    }

    public function agreesWith(self $other): bool
    {
        if (null !== $this->number && null !== $other->number) {
            $gap = max($this->min ?? $this->number, $other->min ?? $other->number) - min($this->max ?? $this->number, $other->max ?? $other->number);
            if ($gap <= 1e-9) {
                return true;
            }

            if ('mm' !== $this->unit || 'mm' !== $other->unit) {
                return false;
            }

            $larger = max(abs($this->max ?? $this->number), abs($other->max ?? $other->number));

            return $gap <= max(self::MM_FLOOR, self::MM_RATIO * $larger) + 1e-9;
        }

        return mb_strtolower($this->value) === mb_strtolower($other->value);
    }

    public function widenedBy(self $other): self
    {
        if (null === $this->number || null === $other->number) {
            return $this;
        }

        return new self(
            $this->key,
            $this->label,
            $this->value,
            $this->number,
            $this->unit,
            min($this->min ?? $this->number, $other->min ?? $other->number),
            max($this->max ?? $this->number, $other->max ?? $other->number),
            $this->info,
        );
    }

    /**
     * @return array{label: string, value: string, min?: float, max?: float, unit?: ?string, info?: true}
     */
    public function toArray(): array
    {
        $data = ['label' => $this->label, 'value' => $this->value];
        if (null !== $this->number) {
            $data += ['min' => $this->min ?? $this->number, 'max' => $this->max ?? $this->number, 'unit' => $this->unit];
        }
        if ($this->info) {
            $data['info'] = true;
        }

        return $data;
    }
}
