<?php

declare(strict_types=1);

namespace App\Agent\Finder;

final readonly class QuestionOption
{
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }
}
