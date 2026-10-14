<?php

declare(strict_types=1);

namespace App\Knowledge;

final readonly class MakeKnowledgeSyncResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $removed = 0,
        public int $unchanged = 0,
    ) {
    }

    public function summary(): string
    {
        return \sprintf('%d created, %d updated, %d removed, %d unchanged', $this->created, $this->updated, $this->removed, $this->unchanged);
    }
}
