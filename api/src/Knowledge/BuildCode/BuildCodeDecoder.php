<?php

declare(strict_types=1);

namespace App\Knowledge\BuildCode;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.build_code_decoder')]
interface BuildCodeDecoder
{
    public function supports(string $make): bool;

    /**
     * @throws InvalidBuildCode
     */
    public function decode(string $code): DecodedBuildCode;

    /**
     * @throws InvalidBuildCode
     */
    public function fromDate(\DateTimeImmutable $date): DecodedBuildCode;
}
