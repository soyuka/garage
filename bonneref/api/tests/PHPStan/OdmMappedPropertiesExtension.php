<?php

declare(strict_types=1);

namespace App\Tests\PHPStan;

use Doctrine\ODM\MongoDB\Mapping\Attribute\AbstractField;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * phpstan-doctrine only knows ORM metadata. A property mapped by an ODM attribute is read and written by
 * the hydrator (or by a raw collection write keyed on the same field name), never by the class itself.
 */
final class OdmMappedPropertiesExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        return self::isMapped($property, $propertyName);
    }

    public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return self::isMapped($property, $propertyName);
    }

    public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        return self::isMapped($property, $propertyName);
    }

    private static function isMapped(PropertyReflection $property, string $propertyName): bool
    {
        $class = $property->getDeclaringClass()->getNativeReflection();
        if (!$class->hasProperty($propertyName)) {
            return false;
        }

        return [] !== $class->getProperty($propertyName)->getAttributes(AbstractField::class, \ReflectionAttribute::IS_INSTANCEOF);
    }
}
