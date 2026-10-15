<?php

declare(strict_types=1);

namespace App\Agent\Finder;

final class InvalidTurnImage extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
