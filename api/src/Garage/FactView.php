<?php

declare(strict_types=1);

namespace App\Garage;

use ApiPlatform\Metadata\IriConverterInterface;
use App\ApiResource\GarageVehicleFact;
use App\Entity\VehicleFact;

/**
 * Flattens a VehicleFact (with its full provenance) into the array the MCP tools surface,
 * so the LLM can caveat its answer: "per TecDoc" (verified) vs "general knowledge, NOT
 * manufacturer-verified" (unverified llm/user fact). The `@id` is the dereferenceable
 * GarageVehicleFact IRI.
 */
final class FactView
{
    public function __construct(private readonly IriConverterInterface $iriConverter)
    {
    }

    /**
     * @return array{@id: string, key: string, value: string, unit: string|null, source: string, verified: bool, addedAt: string, addedBy: string|null, url: string|null}
     */
    public function toArray(VehicleFact $fact): array
    {
        return [
            '@id' => $this->iriConverter->getIriFromResource(GarageVehicleFact::fromEntity($fact)),
            'key' => $fact->getKey(),
            'value' => $fact->getValue(),
            'unit' => $fact->getUnit(),
            'source' => $fact->getSource(),
            'verified' => $fact->isVerified(),
            'addedAt' => $fact->getAddedAt()->format(\DateTimeInterface::ATOM),
            'addedBy' => $fact->getAddedBy(),
            'url' => $fact->getUrl(),
        ];
    }
}
