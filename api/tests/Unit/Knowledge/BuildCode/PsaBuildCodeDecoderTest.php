<?php

declare(strict_types=1);

namespace App\Tests\Unit\Knowledge\BuildCode;

use App\Knowledge\BuildCode\InvalidBuildCode;
use App\Knowledge\BuildCode\PsaBuildCodeDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PsaBuildCodeDecoderTest extends TestCase
{
    private PsaBuildCodeDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new PsaBuildCodeDecoder();
    }

    #[DataProvider('supportedMakes')]
    public function testSupportsPsaMakesCaseAndAccentInsensitively(string $make): void
    {
        $this->assertTrue($this->decoder->supports($make));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedMakes(): iterable
    {
        foreach (['PEUGEOT', 'Peugeot', 'CITROEN', 'Citroën', 'CITROËN', 'DS', 'ds'] as $make) {
            yield $make => [$make];
        }
    }

    public function testDoesNotSupportOtherMakes(): void
    {
        $this->assertFalse($this->decoder->supports('Renault'));
    }

    #[DataProvider('oprDates')]
    public function testDecodesAnOrganisationNumberToItsDate(string $opr, string $date): void
    {
        $decoded = $this->decoder->decode($opr);

        $this->assertSame('opr', $decoded->kind);
        $this->assertSame(str_pad(ltrim($opr, '0'), 5, '0', \STR_PAD_LEFT), $decoded->organisationNumber);
        $this->assertSame($date, $decoded->date->format('Y-m-d'));
        $this->assertNull($decoded->plantCode);
        $this->assertNotEmpty($decoded->caveats);
        $this->assertNotEmpty($decoded->sources);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oprDates(): iterable
    {
        yield '9256' => ['9256', '2002-03-13'];
        yield '7969' => ['7969', '1998-09-03'];
        yield '8701' => ['8701', '2000-09-04'];
        yield '10006' => ['10006', '2004-04-01'];
        yield '10537' => ['10537', '2005-09-14'];
        yield '10310' => ['10310', '2005-01-30'];
        yield '10311' => ['10311', '2005-01-31'];
        yield 'zero padded' => ['09256', '2002-03-13'];
    }

    public function testDecodesAnApvIntoOprPlantAndSequence(): void
    {
        $decoded = $this->decoder->decode('102638910232');

        $this->assertSame('apv', $decoded->kind);
        $this->assertSame('10263', $decoded->organisationNumber);
        $this->assertSame('2004-12-14', $decoded->date->format('Y-m-d'));
        $this->assertSame('89', $decoded->plantCode);
        $this->assertSame('Sochaux', $decoded->plantName);
        $this->assertSame('10232', $decoded->sequence);
    }

    public function testDecodesADamWithADroppedLeadingZero(): void
    {
        $decoded = $this->decoder->decode('982488');

        $this->assertSame('dam', $decoded->kind);
        $this->assertSame('09824', $decoded->organisationNumber);
        $this->assertSame('2003-10-02', $decoded->date->format('Y-m-d'));
        $this->assertSame('88', $decoded->plantCode);
        $this->assertSame('Mulhouse', $decoded->plantName);
        $this->assertNull($decoded->sequence);
    }

    public function testDecodesASevenDigitDam(): void
    {
        $decoded = $this->decoder->decode('1026389');

        $this->assertSame('dam', $decoded->kind);
        $this->assertSame('10263', $decoded->organisationNumber);
        $this->assertSame('Sochaux', $decoded->plantName);
    }

    public function testApvWithLettersInsteadOfPlantDigitsKeepsTheOprAndLeavesThePlantUnknown(): void
    {
        $decoded = $this->decoder->decode('10823CJ 20494');

        $this->assertSame('apv', $decoded->kind);
        $this->assertSame('10823', $decoded->organisationNumber);
        $this->assertNull($decoded->plantCode);
        $this->assertNull($decoded->plantName);
        $this->assertSame('20494', $decoded->sequence);
    }

    public function testApvTolerantOfSpacesAndLowerCase(): void
    {
        $decoded = $this->decoder->decode(' 10263 89 10232 ');

        $this->assertSame('10263', $decoded->organisationNumber);
        $this->assertSame('89', $decoded->plantCode);
    }

    public function testUnknownPlantCodeIsKeptWithoutAName(): void
    {
        $decoded = $this->decoder->decode('1026355');

        $this->assertSame('55', $decoded->plantCode);
        $this->assertNull($decoded->plantName);
    }

    public function testCaveatsMentionTheOrderDateDispute(): void
    {
        $caveats = implode(' ', $this->decoder->decode('10263')->caveats);

        $this->assertStringContainsString('commande', $caveats);
    }

    public function testReversesADateToItsOrganisationNumber(): void
    {
        $decoded = $this->decoder->fromDate(new \DateTimeImmutable('2004-09-15'));

        $this->assertSame('10173', $decoded->organisationNumber);
        $this->assertSame('2004-09-15', $decoded->date->format('Y-m-d'));
        $this->assertSame('date', $decoded->kind);
    }

    #[DataProvider('invalidCodes')]
    public function testRejectsAnInvalidCodeWithADomainError(string $code): void
    {
        $this->expectException(InvalidBuildCode::class);

        $this->decoder->decode($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too short' => ['123'];
        yield 'eight digits' => ['12345678'];
        yield 'letters' => ['ABCDE'];
        yield 'too long' => ['1234567890123456'];
        yield 'zero' => ['00000'];
    }

    public function testRejectsADateBeforeTheCounterStarts(): void
    {
        $this->expectException(InvalidBuildCode::class);

        $this->decoder->fromDate(new \DateTimeImmutable('1976-11-08'));
    }

    public function testRejectsADateBeyondTheFiveDigitCounter(): void
    {
        $this->expectException(InvalidBuildCode::class);

        $this->decoder->fromDate(new \DateTimeImmutable('2300-01-01'));
    }
}
