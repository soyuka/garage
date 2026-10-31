<?php

declare(strict_types=1);

namespace App\Tests\Unit\RegistrationDocument;

use App\RegistrationDocument\RegistrationDocumentExtraction;
use App\RegistrationDocument\RegistrationDocumentExtractor;
use App\RegistrationDocument\RegistrationDocumentReader;
use App\Vin\VinDecoder;
use PHPUnit\Framework\TestCase;

final class RegistrationDocumentExtractorTest extends TestCase
{
    public function testNormalisesPlateAndDateAndKeepsValidVinQuiet(): void
    {
        $result = $this->extract(['A' => 'ab 123 cd', 'B' => '15/09/2004', 'E' => 'vf33ckfwb83471925', 'D.1' => ' PEUGEOT ', 'legible' => true])->toArray();

        $this->assertSame('AB-123-CD', $result['A']);
        $this->assertSame('2004-09-15', $result['B']);
        $this->assertSame('VF33CKFWB83471925', $result['E']);
        $this->assertSame('PEUGEOT', $result['D.1']);
        $this->assertTrue($result['legible']);
        $this->assertSame([], $result['warnings']);
    }

    public function testWarnsOnAnInvalidVin(): void
    {
        $result = $this->extract(['E' => 'VF33CKFWB8325463', 'legible' => true])->toArray();

        $this->assertSame(['VIN invalide (somme de contrôle)'], $result['warnings']);
    }

    public function testWarnsOnAPlateOutsideTheSivFormatAndKeepsIt(): void
    {
        $result = $this->extract(['A' => '1234 ab 56', 'legible' => true])->toArray();

        $this->assertSame('1234AB56', $result['A']);
        $this->assertSame(['Plaque illisible'], $result['warnings']);
    }

    public function testDropsAnUnparseableDate(): void
    {
        $result = $this->extract(['B' => 'last spring', 'legible' => true])->toArray();

        $this->assertNull($result['B']);
        $this->assertSame(['Date de première immatriculation illisible'], $result['warnings']);
    }

    public function testMapsKnownModelWarningCodesToFrench(): void
    {
        $result = $this->extract(['legible' => false, 'warnings' => ['blurry', 'glare']])->toArray();

        $this->assertSame(['Photo floue : reprenez-la de face', 'Reflet sur le document : changez l’angle'], $result['warnings']);
    }

    public function testFreeTextModelWarningsNeverReachTheUi(): void
    {
        $result = $this->extract(['legible' => true, 'warnings' => ['blurry', 'E: last characters hard to read', 'DUPONT looks smudged']])->toArray();

        $this->assertSame(['Photo floue : reprenez-la de face', 'Lecture incomplète, vérifiez les champs'], $result['warnings']);
        $this->assertStringNotContainsString('DUPONT', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testNeverCarriesHolderDataEvenIfTheModelReturnsIt(): void
    {
        $result = $this->extract([
            'A' => 'AB-123-CD',
            'C.1' => 'DUPONT JEAN',
            'C.4.1' => '12 RUE DES LILAS',
            'holder' => 'DUPONT',
            'legible' => true,
        ])->toArray();

        $this->assertSame(
            ['A', 'B', 'D.1', 'D.2', 'D.2.1', 'D.3', 'E', 'J.1', 'K', 'P.1', 'P.2', 'P.3', 'P.6', 'legible', 'warnings'],
            array_keys($result),
        );
        $this->assertStringNotContainsString('DUPONT', json_encode($result, \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('LILAS', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testMissingLegibleDefaultsToFalse(): void
    {
        $this->assertFalse($this->extract([])->toArray()['legible']);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function extract(array $raw): RegistrationDocumentExtraction
    {
        $reader = new class($raw) implements RegistrationDocumentReader {
            /**
             * @param array<string, mixed> $raw
             */
            public function __construct(private readonly array $raw)
            {
            }

            public function read(string $content, string $mimeType): array
            {
                return $this->raw;
            }
        };

        return (new RegistrationDocumentExtractor($reader, new VinDecoder()))->extract('x', 'image/png');
    }
}
