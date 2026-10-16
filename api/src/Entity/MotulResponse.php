<?php

declare(strict_types=1);

namespace App\Entity;

use App\Persistence\BsonPayload;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * One Motul Oil Advisor response, kept for good: `search.<sha1>` holds the vehicle search hits, `recs.<sha1>`
 * the raw recommendations page (see MotulClient). Written and read through
 * {@see \App\Motul\MongoMotulResponseStore} only, outside the unit of work.
 */
#[ODM\Document(collection: 'motul_responses')]
class MotulResponse
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $data = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $dataJson = null;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $fetchedAt;

    /**
     * @return array<mixed>
     */
    public function getData(): array
    {
        return BsonPayload::decode($this->data, $this->dataJson);
    }
}
