<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

#[ODM\EmbeddedDocument]
class VehicleDocumentPage
{
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $label = null;

    public function __construct(
        #[ODM\Field(type: 'int')]
        private int $number,
        #[ODM\Field(type: 'string')]
        private string $text,
        ?string $label = null,
    ) {
        $this->label = $label;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
