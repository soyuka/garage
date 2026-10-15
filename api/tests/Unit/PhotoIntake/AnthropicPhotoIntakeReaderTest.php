<?php

declare(strict_types=1);

namespace App\Tests\Unit\PhotoIntake;

use App\PhotoIntake\AnthropicPhotoIntakeReader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Test\InMemoryPlatform;

final class AnthropicPhotoIntakeReaderTest extends TestCase
{
    public function testOneVisionCallWhoseSchemaNeverAsksForSectionC(): void
    {
        $captured = [];
        $platform = new InMemoryPlatform(static function ($model, $input, array $options) use (&$captured): string {
            $captured = ['input' => $input, 'options' => $options];

            return '{"kind":"part","legible":true,"warnings":[],"part":{"familyGuess":"filtre à huile","printedReferences":["W 712/73"],"brandGuess":"MANN-FILTER"}}';
        });

        $raw = (new AnthropicPhotoIntakeReader($platform, 'claude-sonnet-5-5?max_tokens=2048'))->read('jpeg-bytes', 'image/jpeg');

        $this->assertSame('part', $raw['kind']);
        $image = array_values(array_filter($captured['input']->getUserMessage()->getContent(), static fn ($p) => $p instanceof Image))[0] ?? null;
        $this->assertSame('jpeg-bytes', $image?->asBinary());
        $this->assertStringContainsString('section C', (string) $captured['input']->getSystemMessage()?->getContent());

        $schema = $captured['options']['response_format']['json_schema']['schema'];
        $this->assertSame([], array_values(array_filter(array_keys($schema['properties']), static fn (string $k): bool => str_starts_with($k, 'C'))));
        $this->assertSame(['carte_grise', 'part', 'other'], $schema['properties']['kind']['enum']);
        $this->assertSame(['familyGuess', 'printedReferences', 'brandGuess'], array_keys($schema['properties']['part']['properties']));
        $this->assertSame(array_keys($schema['properties']), $schema['required']);
    }
}
