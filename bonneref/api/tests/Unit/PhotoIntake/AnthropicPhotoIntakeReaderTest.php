<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhotoIntake;

use App\PhotoIntake\AnthropicPhotoIntakeReader;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
use App\Tests\Support\AnthropicStructuredPlatform;
use App\Tests\Support\RecordingSpendRecorder;
use PHPUnit\Framework\TestCase;

final class AnthropicPhotoIntakeReaderTest extends TestCase
{
    public function testOneVisionCallWhoseSchemaNeverAsksForSectionC(): void
    {
        $request = [];
        $raw = $this->reader('{"kind":"part","legible":true,"warnings":[],"part":{"familyGuess":"filtre à huile","printedReferences":["W 712/73"],"brandGuess":"MANN-FILTER"}}', $request)->read('jpeg-bytes', 'image/jpeg');

        $this->assertSame('part', $raw['kind']);
        $this->assertSame('W 712/73', $raw['part']['printedReferences'][0]);

        $blocks = array_column($request['messages'][0]['content'], 'type');
        $this->assertContains('image', $blocks);
        $system = json_encode($request['system']);
        $this->assertStringContainsString('section C', $system);
        $this->assertStringContainsString('build_label', $system);
        $this->assertStringContainsString('OPR', $system);

        $schema = $request['output_config']['format']['schema'];
        $this->assertSame([], array_values(array_filter(array_keys($schema['properties']), static fn (string $k): bool => str_starts_with($k, 'C'))));
        $this->assertSame(['carte_grise', 'part', 'build_label', 'other'], $schema['properties']['kind']['enum']);
        $this->assertSame(['opr', 'printedCodes'], array_keys($schema['properties']['buildLabel']['properties']));
        $this->assertSame(['opr', 'printedCodes'], $schema['properties']['buildLabel']['required']);
        $this->assertSame(['familyGuess', 'printedReferences', 'brandGuess'], array_keys($schema['properties']['part']['properties']));
        $this->assertSame(array_keys($schema['properties']), $schema['required']);
    }

    public function testRejectsANonJsonAnswer(): void
    {
        $this->expectException(RegistrationDocumentUnreadable::class);

        $this->reader('I cannot read this')->read('x', 'image/png');
    }

    /**
     * @param array<string, mixed>|null $request
     */
    private function reader(string $answer, ?array &$request = null): AnthropicPhotoIntakeReader
    {
        return new AnthropicPhotoIntakeReader(AnthropicStructuredPlatform::answering($answer, $request), 'claude-sonnet-5-5?max_tokens=2048');
    }

    public function testTheVisionCallIsChargedToTheOwnersSpend(): void
    {
        $spend = new RecordingSpendRecorder();
        $reader = new AnthropicPhotoIntakeReader(AnthropicStructuredPlatform::answering('{"kind":"other","legible":true,"warnings":[]}'), 'claude-sonnet-5-5?max_tokens=2048', $spend);

        try {
            $reader->read('x', 'image/png');
        } catch (RegistrationDocumentUnreadable) {
        }

        $this->assertSame([['model' => 'claude-sonnet-5-5?max_tokens=2048', 'promptTokens' => 1, 'completionTokens' => 1]], $spend->records);
    }
}
