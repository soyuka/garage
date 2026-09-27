<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcription;

use App\Transcription\Keyterms;
use PHPUnit\Framework\TestCase;

/**
 * The vendor's constraints on a bias list, enforced before anything is sent. They are hard limits at
 * ElevenLabs (a term too long or carrying a forbidden character is a 400 for the WHOLE request), so a
 * garage that happens to hold a car with a bracket in its model name must not silently lose its
 * operator the ability to dictate.
 */
final class KeytermsTest extends TestCase
{
    public function testKeepsOrderAndDropsBlanks(): void
    {
        $this->assertSame(['Mazda', 'Clio'], Keyterms::normalize(['Mazda', '   ', 'Clio', '']));
    }

    public function testTrimsEachTerm(): void
    {
        $this->assertSame(['Mazda 6'], Keyterms::normalize(['  Mazda 6  ']));
    }

    /** Case-insensitively: "MAZDA" and "Mazda" are one term to a transcriber, and one slot of 100. */
    public function testDedupesIgnoringCase(): void
    {
        $this->assertSame(['MAZDA', 'Clio'], Keyterms::normalize(['MAZDA', 'Mazda', 'Clio', 'mazda']));
    }

    /** `< > { } [ ] \` are refused by the vendor. Strip them rather than lose the term — or the call. */
    public function testStripsForbiddenCharacters(): void
    {
        $this->assertSame(['Mazda 6 MPS'], Keyterms::normalize(['Mazda [6] <MPS>']));
    }

    public function testDropsTermsOverFiftyCharacters(): void
    {
        $long = str_repeat('a', 51);

        $this->assertSame(['Clio'], Keyterms::normalize([$long, 'Clio']));
    }

    /** A term is a name, not a sentence: the vendor caps it at five words. */
    public function testDropsTermsOverFiveWords(): void
    {
        $this->assertSame(
            ['un deux trois quatre cinq'],
            Keyterms::normalize(['un deux trois quatre cinq', 'un deux trois quatre cinq six']),
        );
    }

    /**
     * 100 is the vendor's ceiling, and the list is built owner-terms-first so a large garage keeps its
     * own vocabulary and loses only the generic filler — never the reverse.
     */
    public function testCapsAtOneHundredTermsKeepingTheFirst(): void
    {
        $terms = array_map(static fn (int $i): string => "term$i", range(1, 130));

        $normalized = Keyterms::normalize($terms);

        $this->assertCount(100, $normalized);
        $this->assertSame('term1', $normalized[0]);
        $this->assertSame('term100', $normalized[99]);
    }

    /** Collapsed inner whitespace, so a two-space model name is not a different term from one space. */
    public function testCollapsesInnerWhitespace(): void
    {
        $this->assertSame(['Mazda 6'], Keyterms::normalize(["Mazda \n  6"]));
    }
}
