<?php

declare(strict_types=1);

namespace App\Entity;

use App\Knowledge\MakeName;
use App\Repository\MakeKnowledgeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Uid\Ulid;

// GLOBAL on purpose: the one deliberate exception to owner tenancy. This is reference knowledge about
// a make (shared by every garage), written only by app:make-knowledge:sync from config/knowledge/*.yaml
// and read-only to agents and MCP clients — there is no `owner` field and no write tool.
#[ODM\Document(collection: 'make_knowledge', repositoryClass: MakeKnowledgeRepository::class)]
#[ODM\Index(keys: ['makes' => 'asc', 'topic' => 'asc'], name: 'idx_make_knowledge_makes_topic')]
class MakeKnowledge
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    /** @var list<string> */
    #[ODM\Field(type: 'collection')]
    private array $makes;

    /** @var Collection<int, MakeKnowledgeSource> */
    #[ODM\EmbedMany(targetDocument: MakeKnowledgeSource::class, strategy: 'set')]
    private Collection $sources;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string>               $makes
     * @param list<MakeKnowledgeSource> $sources
     */
    public function __construct(
        array $makes,
        #[ODM\Field(type: 'string')]
        private string $topic,
        #[ODM\Field(type: 'string')]
        private string $title,
        #[ODM\Field(type: 'string')]
        private string $body,
        array $sources,
        #[ODM\Field(type: 'bool')]
        private bool $verified,
    ) {
        $this->id = (string) new Ulid();
        $this->makes = self::normalizeMakes($makes);
        $this->sources = new ArrayCollection($sources);
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<string> $makes
     *
     * @return list<string>
     */
    public static function normalizeMakes(array $makes): array
    {
        $normalized = array_values(array_unique(array_map(MakeName::normalize(...), $makes)));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<MakeKnowledgeSource> $sources
     *
     * @return bool whether anything changed (updatedAt only moves when it did)
     */
    public function revise(string $title, string $body, array $sources, bool $verified): bool
    {
        $newSources = array_map(static fn (MakeKnowledgeSource $s): array => $s->toArray(), $sources);
        if ($title === $this->title && $body === $this->body && $verified === $this->verified && $newSources === array_map(static fn (MakeKnowledgeSource $s): array => $s->toArray(), $this->sources->toArray())) {
            return false;
        }

        $this->title = $title;
        $this->body = $body;
        $this->verified = $verified;
        $this->sources = new ArrayCollection($sources);
        $this->updatedAt = new \DateTimeImmutable();

        return true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function getMakes(): array
    {
        return $this->makes;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return list<MakeKnowledgeSource>
     */
    public function getSources(): array
    {
        return array_values($this->sources->toArray());
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
