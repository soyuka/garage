<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * A typed spec (oil_type, oil_capacity, spark_plug_ref…) with full provenance, embedded on a
 * {@see PlannedMaintenance} item as an `attributes` entry keyed by `key` (the EmbedMany 'set'
 * strategy stores them as a `{key: {...}}` map, so one-per-key is structural per item). The item
 * list is the single fact store; a vehicle's facts are PROJECTED from its items by
 * {@see \App\Maintenance\VehicleFactsProjector}. `verified` (derived from the source — catalog/SIV
 * authoritative, agent not) is what stops a hallucinated value shadowing an authoritative one, and
 * doubles as the future promotion marker (see the root TODO.md "Architecture — Garage store").
 *
 * No own identity: a spec is addressed through its item/vehicle (inlined on the item GET and the
 * MCP get_vehicle_facts result), never dereferenced on its own.
 */
#[ODM\EmbeddedDocument]
class VehicleFact
{
    // A fact's `verified` flag is decided by its source: catalog (TecDoc/partsapi) and SIV facts are
    // authoritative; agent facts (web/llm/user) are not. The projection uses it for precedence — a
    // verified fact is never shadowed by an unverified one for the same key.
    public const array AUTHORITATIVE_SOURCES = ['partsapi', 'siv'];

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

    public static function isAuthoritativeSource(string $source): bool
    {
        return \in_array($source, self::AUTHORITATIVE_SOURCES, true);
    }

    /**
     * Build a fact from a source, deriving `verified` from it (catalog/SIV = authoritative).
     */
    public static function fromSource(
        string $key,
        string $value,
        string $source,
        ?string $unit = null,
        ?string $addedBy = null,
        ?string $url = null,
        \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
    ): self {
        return new self(
            key: $key,
            value: $value,
            source: $source,
            verified: self::isAuthoritativeSource($source),
            addedAt: $addedAt,
            unit: $unit,
            addedBy: $addedBy,
            url: $url,
        );
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
