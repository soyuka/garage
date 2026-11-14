<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

/**
 * Metadata of one file attached to a turn, embedded in {@see AgentTurn}. Never the binary: the bytes
 * are kept only if the agent files them, and then on the vehicle document or the bill.
 */
#[ODM\EmbeddedDocument]
class AgentTurnAttachment
{
    public function __construct(
        #[ODM\Field(type: 'string')]
        private string $id,
        #[ODM\Field(type: 'string')]
        private string $name,
        #[ODM\Field(type: 'string')]
        private string $contentType,
        #[ODM\Field(type: 'int')]
        private int $size,
        #[ODM\Field(type: 'string', nullable: true)]
        private ?string $filedAs = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getFiledAs(): ?string
    {
        return $this->filedAs;
    }

    public function markFiled(string $as): void
    {
        $this->filedAs = $as;
    }
}
