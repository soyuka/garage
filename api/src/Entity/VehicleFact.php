<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * A typed fact about a vehicle (oil_type, oil_capacity, spark_plug_ref…) with full provenance,
 * embedded on its {@see Vehicle} keyed by `key` (the EmbedMany 'set' strategy stores the facts
 * as a `{key: {...}}` map, so one-per-key is structural — no unique index, no join). `verified`
 * is what stops a hallucinated capacity becoming a confident lie, and doubles as the future
 * promotion marker (see docs/TODO.md "## Garage").
 *
 * No own identity: a fact is addressed through its vehicle (inlined on the item GET and the MCP
 * get_vehicle_facts result), never dereferenced on its own.
 */
#[ODM\EmbeddedDocument]
class VehicleFact
{
    public function __construct(
        #[ODM\Field(type: 'string')]
        private string $key,
        #[ODM\Field(type: 'string')]
        private string $value,
        #[ODM\Field(type: 'string')]
        private string $source,
        #[ODM\Field(type: 'bool')]
        private bool $verified,
        #[ODM\Field(type: 'date_immutable')]
        private \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $unit = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $addedBy = null,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $url = null,
    ) {
    }

    /**
     * Overwrite the mutable fields, keeping the identity (the `key` it is mapped under). Used by
     * the resolver to update a fact in place instead of stacking a duplicate for the same key.
     */
    public function update(
        string $value,
        string $source,
        bool $verified,
        ?string $unit,
        ?string $addedBy,
        ?string $url,
        \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
    ): void {
        $this->value = $value;
        $this->source = $source;
        $this->verified = $verified;
        $this->unit = $unit;
        $this->addedBy = $addedBy;
        $this->url = $url;
        $this->addedAt = $addedAt;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): ?string
    {
        return $this->addedBy;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }
}
