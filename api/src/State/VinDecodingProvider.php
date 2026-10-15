<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\VinDecoding;
use App\Vin\VinDecoder;

/**
 * @implements ProviderInterface<VinDecoding>
 */
final class VinDecodingProvider implements ProviderInterface
{
    public function __construct(private readonly VinDecoder $decoder)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): VinDecoding
    {
        return $this->decoder->decode((string) $uriVariables['vin']);
    }
}
