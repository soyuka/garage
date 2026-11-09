<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\I18n\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    public function testFrenchIsTheDefault(): void
    {
        $this->assertSame(Locale::Fr, Locale::DEFAULT);
    }

    #[DataProvider('tags')]
    public function testResolvesAnyRequestShapedTagOntoASupportedLocale(?string $tag, Locale $expected): void
    {
        $this->assertSame($expected, Locale::resolve($tag));
    }

    /** @return iterable<string, array{?string, Locale}> */
    public static function tags(): iterable
    {
        yield 'exact' => ['fr', Locale::Fr];
        yield 'exact english' => ['en', Locale::En];
        yield 'region-qualified folds onto the primary subtag' => ['en-GB', Locale::En];
        yield 'uppercase region' => ['FR-CA', Locale::Fr];

        // Total by design: a voice endpoint is garnish and must never be what 500s a turn.
        yield 'unsupported language' => ['de-DE', Locale::Fr];
        yield 'empty' => ['', Locale::Fr];
        yield 'null' => [null, Locale::Fr];
        yield 'nonsense' => ['not a language tag', Locale::Fr];
    }
}
