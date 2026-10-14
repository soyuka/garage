<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

#[ODM\EmbeddedDocument]
class MakeKnowledgeSource
{
    public function __construct(
        #[ODM\Field(type: 'string')]
        private string $url,
        #[ODM\Field(type: 'string')]
        private string $label,
        #[ODM\Field(type: 'bool')]
        private bool $verified,
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    /**
     * @return array{url: string, label: string, verified: bool}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'label' => $this->label, 'verified' => $this->verified];
    }
}
