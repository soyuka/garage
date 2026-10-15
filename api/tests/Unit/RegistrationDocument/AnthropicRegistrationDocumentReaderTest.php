<?php

declare(strict_types=1);

namespace App\Tests\Unit\RegistrationDocument;

use App\RegistrationDocument\AnthropicRegistrationDocumentReader;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Test\InMemoryPlatform;

final class AnthropicRegistrationDocumentReaderTest extends TestCase
{
    public function testSendsTheImageWithAStructuredSchemaThatNeverAsksForSectionC(): void
    {
        $captured = [];
        $reader = $this->reader('{"A":"AB-123-CD","legible":true,"warnings":[]}', $captured);

        $fields = $reader->read('jpeg-bytes', 'image/jpeg');

        $this->assertSame('AB-123-CD', $fields['A']);
        $this->assertSame('claude-sonnet-5-5?max_tokens=2048', $captured['model']);

        $this->assertInstanceOf(MessageBag::class, $captured['input']);
        $system = (string) $captured['input']->getSystemMessage()?->getContent();
        $this->assertStringContainsString('section C', $system);
        $this->assertStringContainsString('ignore', strtolower($system));

        $parts = $captured['input']->getUserMessage()->getContent();
        $image = array_values(array_filter($parts, static fn ($p) => $p instanceof Image))[0] ?? null;
        $this->assertNotNull($image);
        $this->assertSame('jpeg-bytes', $image->asBinary());
        $this->assertSame('image/jpeg', $image->getFormat());

        $schema = $captured['options']['response_format']['json_schema']['schema'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertEqualsCanonicalizing(
            ['A', 'B', 'D.1', 'D.2', 'D.2.1', 'D.3', 'E', 'J.1', 'K', 'P.1', 'P.2', 'P.3', 'P.6', 'legible', 'warnings'],
            array_keys($schema['properties']),
        );
        $this->assertSame(array_keys($schema['properties']), $schema['required']);
    }

    public function testSendsAPdfAsADocument(): void
    {
        $captured = [];
        $reader = $this->reader('{"legible":true,"warnings":[]}', $captured);

        $reader->read('%PDF-1.4', 'application/pdf');

        $parts = $captured['input']->getUserMessage()->getContent();
        $this->assertNotEmpty(array_filter($parts, static fn ($p) => $p instanceof Document));
    }

    public function testRejectsANonJsonAnswer(): void
    {
        $captured = [];
        $reader = $this->reader('I cannot read this', $captured);

        $this->expectException(RegistrationDocumentUnreadable::class);

        $reader->read('x', 'image/png');
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function reader(string $answer, array &$captured): AnthropicRegistrationDocumentReader
    {
        $platform = new InMemoryPlatform(static function ($model, $input, array $options) use ($answer, &$captured): string {
            $captured = ['model' => $model->getName(), 'input' => $input, 'options' => $options];

            return $answer;
        });

        return new AnthropicRegistrationDocumentReader($platform, 'claude-sonnet-5-5?max_tokens=2048');
    }
}
