<?php

declare(strict_types=1);

namespace App\Maintenance;

final readonly class PartRefRewriter
{
    /**
     * @param array<array-key, mixed>     $parts
     * @param list<array{string, string}> $changes    [from, to] per rewritten ref
     * @param list<string>                $collisions refs a rewrite made two lines share
     */
    private function __construct(
        public array $parts,
        public array $changes,
        public array $collisions,
    ) {
    }

    /**
     * @param array<array-key, mixed> $parts
     */
    public static function rewrite(array $parts): self
    {
        $changes = [];
        $rewritten = [];
        foreach ($parts as $i => $part) {
            if (!\is_array($part) || !\is_string($part['ref'] ?? null)) {
                continue;
            }
            $label = isset($part['label']) && \is_string($part['label']) ? $part['label'] : null;
            [$ref, $newLabel] = ConsumableStandard::resolve($part['ref'], $label);
            if ($ref === $part['ref']) {
                continue;
            }
            $changes[] = [$part['ref'], $ref];
            $rewritten[$i] = $ref;
            $parts[$i]['ref'] = $ref;
            if ($newLabel !== $label) {
                $parts[$i]['label'] = $newLabel;
            }
        }

        $collisions = [];
        foreach ($rewritten as $i => $ref) {
            foreach ($parts as $j => $other) {
                if ($i !== $j && \is_array($other) && ($other['ref'] ?? null) === $ref && '' !== $ref) {
                    $collisions[$ref] = $ref;
                }
            }
        }

        return new self($parts, $changes, array_values($collisions));
    }
}
