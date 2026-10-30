<?php

declare(strict_types=1);

namespace App\Tests\Unit\Knowledge;

use App\Knowledge\MakeName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MakeNameTest extends TestCase
{
    #[DataProvider('makes')]
    public function testNormalizesCaseAccentsAndWhitespace(string $raw, string $expected): void
    {
        self::assertSame($expected, MakeName::normalize($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function makes(): iterable
    {
        yield 'mixed case' => ['Peugeot', 'PEUGEOT'];
        yield 'accent' => ['Citroën', 'CITROEN'];
        yield 'upper accent' => ['CITROËN', 'CITROEN'];
        yield 'padded' => ['  ds ', 'DS'];
    }
}
