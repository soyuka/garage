<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * What a photo intake read off a part or a build label, kept for the turn that follows so the page
 * hands back an id rather than the reading as text. Never the photo. Reaped by the TTL index.
 */
#[ODM\Document(collection: 'intake_readings')]
#[ODM\Index(keys: ['expiresAt' => 'asc'], name: 'ttl_intake_readings', expireAfterSeconds: 0)]
class IntakeReading
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    public private(set) string $id;

    /**
     * @param array{familyGuess: ?string, printedReferences: list<string>, brandGuess: ?string}|null $part
     * @param array{opr: ?string, printedCodes: list<string>}|null                                   $buildLabel
     */
    public function __construct(
        #[ODM\Field(type: 'string')]
        public private(set) string $owner,
        #[ODM\Field(type: 'hash', nullable: true)]
        public private(set) ?array $part,
        #[ODM\Field(type: 'hash', nullable: true)]
        public private(set) ?array $buildLabel,
        #[ODM\Field(type: 'date_immutable')]
        public private(set) \DateTimeImmutable $expiresAt,
    ) {
        $this->id = (string) new Ulid();
    }
}
