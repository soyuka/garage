<?php

declare(strict_types=1);

namespace App\Knowledge;

final class MakeName
{
    public static function normalize(string $make): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', trim($make));

        return strtoupper(false === $ascii ? trim($make) : $ascii);
    }
}
