<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\I18n\AgentMessages;
use App\I18n\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentMessagesTest extends TestCase
{
    public function testFrenchKeepsTheSentencesTheAtelierAlreadySpoke(): void
    {
        $this->assertSame('Demande interrompue.', AgentMessages::for(Locale::Fr)->cancelled);
    }

    public function testEnglishIsAWholeCatalogNotAFallback(): void
    {
        $this->assertSame('Request stopped.', AgentMessages::for(Locale::En)->cancelled);
    }

    #[DataProvider('locales')]
    public function testEveryUserFacingSentenceIsNonEmpty(Locale $locale): void
    {
        $m = AgentMessages::for($locale);

        $this->assertNotSame('', trim($m->noOp));
        $this->assertNotSame('', trim($m->notConverged));
        $this->assertNotSame('', trim($m->cancelled));
    }

    /** @return iterable<string, array{Locale}> */
    public static function locales(): iterable
    {
        yield 'fr' => [Locale::Fr];
        yield 'en' => [Locale::En];
    }
}
