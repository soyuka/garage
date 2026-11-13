<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PlannedPart;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class PlannedPartTest extends TestCase
{
    /**
     * ODM hydrates a stored line via newInstanceWithoutConstructor, which never runs the constructor —
     * so a CONSTRUCTOR-PROMOTED property's default is skipped. A legacy line whose BSON omits the
     * optional fields (qty/brand/label) then leaves them uninitialized, and toArray() fatals with
     * "must not be accessed before initialization". Class-level defaults are what survive hydration.
     */
    public function testToArraySurvivesColdHydrationOfALineMissingOptionalFields(): void
    {
        $part = (new \ReflectionClass(PlannedPart::class))->newInstanceWithoutConstructor();
        // Only the keys a legacy BSON doc carried get set by ODM; the rest stay at their class default.
        (new \ReflectionProperty(PlannedPart::class, 'id'))->setValue($part, (string) new Ulid());
        (new \ReflectionProperty(PlannedPart::class, 'ref'))->setValue($part, 'OC90');

        $array = $part->toArray();

        $this->assertSame('OC90', $array['ref']);
        $this->assertArrayNotHasKey('qty', $array, 'an unset optional field is omitted, not fatal');
    }

    public function testAMotorbikeFinderRouteIsKept(): void
    {
        $this->assertTrue(PlannedPart::isValidFindRoute('/k/101222-MC/c/100538'));
        $this->assertFalse(PlannedPart::isValidFindRoute('/k/101222-XX/c/100538'));
    }
}
