<?php

declare(strict_types=1);

namespace App\Garage;

use App\Entity\VehicleFact;

/**
 * Flattens a VehicleFact (with its full provenance) into the array the MCP tools surface, so the
 * LLM can caveat its answer: "per TecDoc" (verified) vs "general knowledge, NOT manufacturer-
 * verified" (unverified llm/user fact). Facts are embedded on their vehicle and carry no own IRI:
 * the value and provenance travel inline here and on the vehicle item GET.
 */
final class FactView
{
    /**
     * @return array{key: string, value: string, unit: string|null, source: string, verified: bool, addedAt: string, addedBy: string|null, url: string|null}
     */
    public function toArray(VehicleFact $fact): array
    {
        return [
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
