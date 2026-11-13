<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\I18n\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    public function testEnglishIsTheDefault(): void
    {
        $this->assertSame(Locale::En, Locale::resolve(null));
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
        yield 'unsupported language' => ['de-DE', Locale::En];
        yield 'empty' => ['', Locale::En];
        yield 'null' => [null, Locale::En];
        yield 'nonsense' => ['not a language tag', Locale::En];
    }
}
