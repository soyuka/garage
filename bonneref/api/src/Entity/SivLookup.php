<?php

declare(strict_types=1);

namespace App\Entity;

use App\Persistence\BsonPayload;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * One paid SIV plate lookup, kept for good, keyed by the normalised plate. The envelope is the upstream's
 * full response, so an unknown plate is an envelope without `data` (a negative hit, never re-billed).
 *
 * Written and read through {@see \App\Siv\MongoSivLookupStore} only, outside the unit of work.
 */
#[ODM\Document(collection: 'siv_lookups')]
class SivLookup
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $envelope = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $envelopeJson = null;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $fetchedAt;

    /**
     * @return array<mixed>
     */
    public function getEnvelope(): array
    {
        return BsonPayload::decode($this->envelope, $this->envelopeJson);
    }
}
