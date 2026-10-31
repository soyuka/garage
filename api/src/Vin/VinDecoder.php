<?php

declare(strict_types=1);

namespace App\Vin;

use App\ApiResource\VinDecoding;
use Sunrise\Vin\Vin;

final class VinDecoder
{
    private const TRANSLITERATION = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
    ];

    private const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    public function decode(string $input): VinDecoding
    {
        $normalized = strtoupper(preg_replace('/[\s-]+/', '', $input) ?? '');

        $decoding = new VinDecoding();
        $decoding->vin = $normalized;

        try {
            $vin = new Vin($normalized);
        } catch (\InvalidArgumentException) {
            $decoding->valid = false;
            $decoding->error = 'A VIN is 17 characters from A-Z and 0-9, without I, O or Q.';

            return $decoding;
        }

        $years = $vin->getModelYear();

        $decoding->valid = true;
        $decoding->wmi = $vin->getWmi();
        $decoding->vds = $vin->getVds();
        $decoding->vis = $vin->getVis();
        $decoding->manufacturer = $vin->getManufacturer();
        $decoding->region = $vin->getRegion();
        $decoding->country = $vin->getCountry();
        $northAmerican = $this->isNorthAmerican($normalized);
        $decoding->modelYear = $northAmerican && [] !== $years ? max($years) : null;
        $decoding->modelYearCodeCandidates = $northAmerican ? [] : $years;
        $decoding->checksumValid = $northAmerican ? $this->checksumMatches($normalized) : null;

        return $decoding;
    }

    private function isNorthAmerican(string $vin): bool
    {
        return str_contains('12345', $vin[0]);
    }

    private function checksumMatches(string $vin): bool
    {
        $sum = 0;
        foreach (str_split($vin) as $i => $char) {
            $sum += (ctype_digit($char) ? (int) $char : self::TRANSLITERATION[$char]) * self::WEIGHTS[$i];
        }

        $remainder = $sum % 11;

        return (10 === $remainder ? 'X' : (string) $remainder) === $vin[8];
    }
}
