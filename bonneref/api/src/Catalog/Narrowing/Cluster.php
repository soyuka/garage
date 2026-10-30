<?php

declare(strict_types=1);

namespace App\Catalog\Narrowing;

final class Cluster
{
    /**
     * @param list<Representative> $members best-ranked first
     */
    public function __construct(
        public readonly string $id,
        public array $members,
        public Fingerprint $fingerprint,
    ) {
    }

    public function best(): Representative
    {
        return $this->members[0];
    }

    public function merged(): Fingerprint
    {
        return Fingerprint::merge(array_map(static fn (Representative $m): Fingerprint => $m->fingerprint, $this->members));
    }

    /**
     * @param non-empty-list<self> $clusters
     */
    public static function combine(string $id, array $clusters): self
    {
        $members = array_merge(...array_map(static fn (self $c): array => $c->members, $clusters));
        usort($members, Representative::compare(...));

        $fingerprint = new Fingerprint();
        foreach ($members as $member) {
            $fingerprint = $fingerprint->completedBy($member->fingerprint);
        }

        return new self($id, $members, $fingerprint);
    }
}
