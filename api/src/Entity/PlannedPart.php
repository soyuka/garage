<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * One line on a {@see PlannedMaintenance} task's shopping list — a part or fluid the work needs.
 * Embedded (EmbedMany, ordered) on the task; unlike {@see VehicleFact} it owns a ULID identity so the
 * dashboard can address a single line for edit/remove through the parts subresource
 * (/garage/planned-tasks/{taskId}/parts/{id}).
 *
 * A ref-less line is the bare fluid line ("Huile moteur ×5") whose derived spec is computed at read
 * time by {@see \App\Maintenance\PlannedTaskFluidEnricher} — never stored here.
 */
#[ODM\EmbeddedDocument]
class PlannedPart
{
    // ALL fields are declared class-level (NOT constructor-promoted) with a default. ODM hydrates a
    // stored line via newInstanceWithoutConstructor — which applies a CLASS-level default but never a
    // promoted param's — so a legacy doc that omits an optional key (qty/brand/label) would leave a
    // promoted property uninitialized and fatal on read. The class defaults are what survive that.
    // `id` is also minted lazily in getId() so a pre-identity line stays addressable.
    // See [[odm-promoted-field-hydration-500]].
    #[ODM\Field(type: 'string')]
    private ?string $id = null;
    #[ODM\Field(type: 'string')]
    private string $ref = '';
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $label = null;
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $brand = null;
    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $qty = null;
    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $priceCents = null;

    public function __construct(
        string $ref = '',
        ?string $label = null,
        ?string $brand = null,
        ?int $qty = null,
        ?int $priceCents = null,
        ?string $id = null,
    ) {
        $this->ref = $ref;
        $this->label = $label;
        $this->brand = $brand;
        $this->qty = $qty;
        $this->priceCents = $priceCents;
        $this->id = $id ?? (string) new Ulid();
    }

    /**
     * @param array{ref?: string, label?: string|null, brand?: string|null, qty?: int|null, priceCents?: int|null, id?: string|null} $part
     */
    public static function fromArray(array $part): self
    {
        return new self(
            ref: (string) ($part['ref'] ?? ''),
            label: $part['label'] ?? null,
            brand: $part['brand'] ?? null,
            qty: isset($part['qty']) ? (int) $part['qty'] : null,
            priceCents: isset($part['priceCents']) ? (int) $part['priceCents'] : null,
            id: $part['id'] ?? null,
        );
    }

    public function getId(): Ulid
    {
        return Ulid::fromString($this->id ??= (string) new Ulid());
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    /**
     * Merge-patch a single line: only the supplied keys overwrite (a `ref` is never blanked by an
     * absent key). The shape mirrors {@see self::fromArray()} minus `id` (identity is immutable).
     *
     * @param array{ref?: string, label?: string|null, brand?: string|null, qty?: int|null, priceCents?: int|null} $part
     */
    public function update(array $part): void
    {
        if (\array_key_exists('ref', $part)) {
            $this->ref = (string) $part['ref'];
        }
        if (\array_key_exists('label', $part)) {
            $this->label = null !== $part['label'] ? (string) $part['label'] : null;
        }
        if (\array_key_exists('brand', $part)) {
            $this->brand = null !== $part['brand'] ? (string) $part['brand'] : null;
        }
        if (\array_key_exists('qty', $part)) {
            $this->qty = null !== $part['qty'] ? (int) $part['qty'] : null;
        }
        if (\array_key_exists('priceCents', $part)) {
            $this->priceCents = null !== $part['priceCents'] ? (int) $part['priceCents'] : null;
        }
    }

    /**
     * @return array{id: string, ref: string, label?: string, brand?: string, qty?: int, priceCents?: int}
     */
    public function toArray(): array
    {
        $out = ['id' => (string) $this->getId(), 'ref' => $this->ref];
        if (null !== $this->label) {
            $out['label'] = $this->label;
        }
        if (null !== $this->brand) {
            $out['brand'] = $this->brand;
        }
        if (null !== $this->qty) {
            $out['qty'] = $this->qty;
        }
        if (null !== $this->priceCents) {
            $out['priceCents'] = $this->priceCents;
        }

        return $out;
    }
}
