<?php

declare(strict_types=1);

namespace App\Tests\Unit\RegistrationDocument;

use App\RegistrationDocument\AnthropicRegistrationDocumentReader;
use App\RegistrationDocument\RegistrationDocumentUnreadable;
use App\Tests\Support\AnthropicStructuredPlatform;
use App\Tests\Support\RecordingSpendRecorder;
use PHPUnit\Framework\TestCase;

final class AnthropicRegistrationDocumentReaderTest extends TestCase
{
    public function testSendsTheImageWithAStructuredSchemaThatNeverAsksForSectionC(): void
    {
        $request = [];
        $fields = $this->reader('{"A":"AB-123-CD","legible":true,"warnings":[]}', $request)->read('jpeg-bytes', 'image/jpeg');

        $this->assertSame('AB-123-CD', $fields['A']);
        $this->assertSame('claude-sonnet-5-5', $request['model']);
        $this->assertStringContainsString('section C', json_encode($request['system']));

        $content = $request['messages'][0]['content'];
        $this->assertSame('image', array_values(array_filter($content, static fn (array $b): bool => 'text' !== $b['type']))[0]['type']);

        $schema = $request['output_config']['format']['schema'];
        $this->assertEqualsCanonicalizing(
            ['A', 'B', 'D.1', 'D.2', 'D.2.1', 'D.3', 'E', 'J.1', 'K', 'P.1', 'P.2', 'P.3', 'P.6', 'legible', 'warnings'],
            array_keys($schema['properties']),
        );
        $this->assertSame(array_keys($schema['properties']), $schema['required']);
    }

    public function testReadsAPdfSentAsADocument(): void
    {
        $request = [];
        $fields = $this->reader('{"A":"AB-123-CD","legible":true,"warnings":[]}', $request)->read('%PDF-1.4', 'application/pdf');

        $this->assertSame('AB-123-CD', $fields['A']);
        $blocks = array_column($request['messages'][0]['content'], 'type');
        $this->assertContains('document', $blocks);
    }

    public function testRejectsANonJsonAnswer(): void
    {
        $this->expectException(RegistrationDocumentUnreadable::class);

        $this->reader('I cannot read this')->read('x', 'image/png');
    }

    /**
     * @param array<string, mixed>|null $request
     */
    private function reader(string $answer, ?array &$request = null): AnthropicRegistrationDocumentReader
    {
        return new AnthropicRegistrationDocumentReader(AnthropicStructuredPlatform::answering($answer, $request), 'claude-sonnet-5-5?max_tokens=2048');
    }

    public function testTheVisionCallIsChargedToTheOwnersSpend(): void
    {
        $spend = new RecordingSpendRecorder();
        $reader = new AnthropicRegistrationDocumentReader(AnthropicStructuredPlatform::answering('{"legible":true,"warnings":[]}'), 'claude-sonnet-5-5?max_tokens=2048', $spend);

        try {
            $reader->read('x', 'image/png');
            // @mago-expect lint:no-empty-catch-clause the read is expected to refuse; the test inspects the recorded spend
        } catch (RegistrationDocumentUnreadable) {
        }

        $this->assertSame([['model' => 'claude-sonnet-5-5?max_tokens=2048', 'promptTokens' => 1, 'completionTokens' => 1]], $spend->records);
    }
}
