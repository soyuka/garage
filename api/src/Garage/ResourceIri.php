<?php

declare(strict_types=1);

namespace App\Garage;

use ApiPlatform\Metadata\IriConverterInterface;

/**
 * The IRI of a resource the API exposes with a GET item operation: API Platform's converter is nullable for
 * resources that have none, which the garage read-models never are.
 */
final class ResourceIri
{
    public static function of(IriConverterInterface $iriConverter, object $resource): string
    {
        return $iriConverter->getIriFromResource($resource) ?? throw new \LogicException(\sprintf('A %s has no IRI.', $resource::class));
    }
}
