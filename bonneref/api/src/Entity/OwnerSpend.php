<?php

declare(strict_types=1);

namespace App\Entity;

use App\Agent\Journal\ModelUsage;
use App\Repository\OwnerSpendRepository;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

/**
 * A billed model call made outside an agent turn (a vision intake), counted in the owner's daily budget
 * next to the turns. An intake writes its estimate before the call (source {@see \App\Security\MongoSpendRecorder::RESERVED})
 * and settles it to the actual usage after. Reaped by the TTL index once it has left the 24-hour window.
 */
#[ODM\Document(collection: 'owner_spend', repositoryClass: OwnerSpendRepository::class)]
#[ODM\Index(keys: ['owner' => 'asc', 'at' => 'asc'], name: 'owner_spend_window')]
#[ODM\Index(keys: ['at' => 'asc'], name: 'ttl_owner_spend', expireAfterSeconds: 172800)]
class OwnerSpend
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    public private(set) string $id;

    #[ODM\Field(type: 'int')]
    public private(set) int $promptTokens;

    #[ODM\Field(type: 'int')]
    public private(set) int $completionTokens;

    #[ODM\Field(type: 'int')]
    public private(set) int $cacheReadTokens;

    #[ODM\Field(type: 'int')]
    public private(set) int $cacheCreationTokens;

    public function __construct(
        #[ODM\Field(type: 'string')]
        public private(set) string $owner,
        #[ODM\Field(type: 'string')]
        public private(set) string $source,
        #[ODM\Field(type: 'string')]
        public private(set) string $model,
        ModelUsage $usage,
        #[ODM\Field(type: 'date_immutable')]
        public private(set) \DateTimeImmutable $at,
    ) {
        $this->id = (string) new Ulid();
        $this->promptTokens = $usage->promptTokens;
        $this->completionTokens = $usage->completionTokens;
        $this->cacheReadTokens = $usage->cacheReadTokens;
        $this->cacheCreationTokens = $usage->cacheCreationTokens;
    }
}
