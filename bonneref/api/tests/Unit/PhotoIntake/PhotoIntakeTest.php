<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhotoIntake;

use App\PhotoIntake\PhotoIntake;
use App\PhotoIntake\PhotoIntakeReader;
use App\PhotoIntake\PhotoKind;
use App\RegistrationDocument\RegistrationDocumentExtractor;
use App\RegistrationDocument\RegistrationDocumentReader;
use App\Vin\VinDecoder;
use PHPUnit\Framework\TestCase;

final class PhotoIntakeTest extends TestCase
{
    public function testABuildLabelComesBackWithItsOprAndBoundedCodes(): void
    {
        $codes = [' DAM 12345 ', "OPR\x0012345", '', 42, 'DAM 12345', str_repeat('X', 200)];
        for ($i = 0; $i < 20; ++$i) {
            $codes[] = 'CODE'.$i;
        }

        $result = $this->intake([
            'kind' => 'build_label',
            'buildLabel' => ['opr' => '  12345 ', 'printedCodes' => $codes],
            'part' => ['familyGuess' => 'rotule', 'printedReferences' => ['X'], 'brandGuess' => null],
            'A' => 'AB-123-CD',
        ])->classify('bytes', 'image/jpeg');

        $this->assertSame(PhotoKind::BuildLabel, $result->kind);
        $array = $result->toArray();
        $this->assertSame('build_label', $array['kind']);
        $this->assertNull($array['registration']);
        $this->assertNull($array['part']);
        $this->assertSame('12345', $array['buildLabel']['opr']);
        $printed = $array['buildLabel']['printedCodes'];
        $this->assertCount(12, $printed);
        $this->assertSame(['DAM 12345', 'OPR 12345'], \array_slice($printed, 0, 2));
        $this->assertSame(60, mb_strlen($printed[2]));
        $this->assertNotContains('', $printed);
    }

    public function testJunkBuildLabelPayloadIsDropped(): void
    {
        $array = $this->intake(['kind' => 'build_label', 'buildLabel' => ['opr' => ['nope'], 'printedCodes' => 'DAM 1']])
            ->classify('bytes', 'image/jpeg')->toArray();

        $this->assertSame(['opr' => null, 'printedCodes' => []], $array['buildLabel']);
    }

    public function testAPartPhotoCarriesANullBuildLabel(): void
    {
        $array = $this->intake([
            'kind' => 'part',
            'part' => ['familyGuess' => 'filtre à huile', 'printedReferences' => ['W 712/73'], 'brandGuess' => 'MANN-FILTER'],
            'buildLabel' => ['opr' => '12345', 'printedCodes' => ['DAM 12345']],
        ])->classify('bytes', 'image/jpeg')->toArray();

        $this->assertSame('part', $array['kind']);
        $this->assertSame(['W 712/73'], $array['part']['printedReferences']);
        $this->assertArrayHasKey('buildLabel', $array);
        $this->assertNull($array['buildLabel']);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function intake(array $raw): PhotoIntake
    {
        $reader = new class($raw) implements PhotoIntakeReader {
            public function __construct(private readonly array $raw)
            {
            }

            public function read(string $content, string $mimeType): array
            {
                return $this->raw;
            }
        };
        $registrationReader = new class implements RegistrationDocumentReader {
            public function read(string $content, string $mimeType): array
            {
                return [];
            }
        };

        return new PhotoIntake($reader, new RegistrationDocumentExtractor($registrationReader, new VinDecoder()));
    }
}
