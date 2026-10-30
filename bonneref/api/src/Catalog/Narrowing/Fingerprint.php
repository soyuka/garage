<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

final readonly class Fingerprint
{
    /**
     * @param array<string, FingerprintEntry> $entries keyed by normalised key
     */
    public function __construct(public array $entries = [])
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    /** A key missing on either side never counts as a disagreement. */
    public function agreesWith(self $other): bool
    {
        foreach ($this->entries as $key => $entry) {
            if ($entry->info || !isset($other->entries[$key]) || $other->entries[$key]->info) {
                continue;
            }
            if (!$entry->agreesWith($other->entries[$key])) {
                return false;
            }
        }

        return true;
    }

    /** Keys already known win, so a cluster's fingerprint is its first member's, completed by later ones. */
    public function completedBy(self $other): self
    {
        return new self($this->entries + $other->entries);
    }

    /**
     * The fingerprint of several parts sold as one: the first part's value per key, widened to the range of all of them.
     *
     * @param list<self> $fingerprints best part first
     */
    public static function merge(array $fingerprints): self
    {
        $entries = [];
        foreach ($fingerprints as $fingerprint) {
            foreach ($fingerprint->entries as $key => $entry) {
                $entries[$key] = isset($entries[$key]) ? $entries[$key]->widenedBy($entry) : $entry;
            }
        }

        return new self($entries);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (FingerprintEntry $e): array => $e->toArray(), $this->entries);
    }
}
