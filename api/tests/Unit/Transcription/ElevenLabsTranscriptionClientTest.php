<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcription;

use App\I18n\Locale;
use App\Transcription\ElevenLabsTranscriptionClient;
use App\Transcription\TranscriptionUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the client does with a vendor that says no.
 *
 * Written after a live outage (2026-09-11): the whole bias list was sent as one JSON field, Scribe
 * refused every request with `400 {"status":"invalid_keyword"}`, and the operator saw a mic that
 * recorded and transcribed nothing. The refusal reached the logs as "Scribe refused the recording
 * (HTTP 400)" and no further — the vendor had SAID which parameter was wrong, in a body this class
 * discarded, and finding it again took a hand-rolled probe against the live API. It is carried now.
 */
final class ElevenLabsTranscriptionClientTest extends TestCase
{
    private const string REFUSAL = '{"detail":{"status":"invalid_keyword","message":"Some keyword contains invalid characters"}}';

    public function testARefusalCarriesTheVendorsOwnExplanation(): void
    {
        $client = new ElevenLabsTranscriptionClient(
            new MockHttpClient(new MockResponse(self::REFUSAL, ['http_code' => 400])),
            'sk_test',
            'scribe_v1',
        );

        try {
            $client->transcribe('bytes', 'audio/webm', Locale::Fr);
            $this->fail('A 400 must not pass for a transcript.');
        } catch (TranscriptionUnavailable $e) {
            $this->assertStringContainsString('400', $e->getMessage());
            $this->assertStringContainsString('invalid_keyword', $e->getMessage());
            $this->assertStringContainsString('Some keyword contains invalid characters', $e->getMessage());
        }
    }

    /** A refusal with nothing to say must still say the status, and must not itself throw. */
    public function testARefusalWithAnEmptyBodyStillReportsTheStatus(): void
    {
        $client = new ElevenLabsTranscriptionClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 503])),
            'sk_test',
            'scribe_v1',
        );

        $this->expectException(TranscriptionUnavailable::class);
        $this->expectExceptionMessageMatches('/503/');
        $client->transcribe('bytes', 'audio/webm', Locale::Fr);
    }
}
