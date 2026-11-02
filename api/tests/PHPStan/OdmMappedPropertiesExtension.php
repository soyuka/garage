<?php

declare(strict_types=1);

namespace App\Tests\PHPStan;

use Doctrine\ODM\MongoDB\Mapping\Attribute\AbstractField;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * phpstan-doctrine only knows ORM metadata. ODM-mapped properties are written by the hydrator and read
 * by the unit of work, so a field only ever queried or persisted is not dead.
 */
final class OdmMappedPropertiesExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    private function isMapped(PropertyReflection $property, string $propertyName): bool
    {
        $class = $property->getDeclaringClass()->getNativeReflection();
        if (!$class->hasProperty($propertyName)) {
            return false;
        }

        return [] !== $class->getProperty($propertyName)->getAttributes(AbstractField::class, \ReflectionAttribute::IS_INSTANCEOF);
    }
}
