<?php

declare(strict_types=1);

namespace App\Entity;

use App\Persistence\BsonPayload;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * One paid partsapi.ru response, kept for good: the id is PartsApiClient's `<method>.<sha1(signature)>`
 * key, unchanged from the PostgreSQL pool so migrated entries stay hits. Only the catalogue snapshots carry
 * an expiry; the TTL index reaps them, and reads also check it because the TTL monitor runs once a minute.
 *
 * Written and read through {@see \App\PartsApi\MongoPartsApiResponseStore} only, outside the unit of work.
 */
#[ODM\Document(collection: 'partsapi_responses')]
#[ODM\Index(keys: ['expiresAt' => 'asc'], name: 'ttl_partsapi_responses', expireAfterSeconds: 0)]
class PartsApiResponse
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'string')]
    private string $method;

    /** Null on entries migrated from the PostgreSQL pool, which only kept the hashed key. */
    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $params = null;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $data = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $dataJson = null;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $fetchedAt;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /**
     * @return array<mixed>
     */
    public function getData(): array
    {
        return BsonPayload::decode($this->data, $this->dataJson);
    }

    public function getId(): string
    {
        return $this->id;
    }
}
