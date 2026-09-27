<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\I18n\Locale;
use App\Transcription\GarageVocabulary;
use App\Tests\Mock\RecordingTranscriptionHttpClient;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /stt — the browser's only route to a transcript, the input twin of `/tts`.
 *
 * Authenticated wholesale by the `api` firewall, and for a sharper reason than the voice's: an open
 * transcription proxy is this machine's CPU being spent by strangers. What comes back is the
 * operator's words VERBATIM — the agent reads a dictated sentence exactly as it reads a typed one
 * (dashboard/CHOREOGRAPHY.md § "La dictée", binding rule *the transcript is the operator's words*).
 *
 * The sidecar transport is {@see RecordingTranscriptionHttpClient}; no test starts a whisper model.
 */
final class TranscriptionTest extends AppTestCase
{
    /** What a browser's MediaRecorder produces, and the only family of bodies this endpoint accepts. */
    private const string AUDIO_TYPE = 'audio/webm;codecs=opus';

    /** Not decodable audio — nothing in the stack decodes it, the backend is a double. */
    private const string AUDIO = "\x1a\x45\xdf\xa3fake-webm-opus-bytes";

    public function testAnonymousIsRefused(): void
    {
        $client = static::createClient();
        $this->post($client, self::AUDIO, authed: false);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame([], $this->transport()->requests, 'An unauthenticated caller must not reach the backend.');
    }

    public function testAuthedRequestReturnsTheTranscript(): void
    {
        $client = static::createClient();
        $this->post($client, self::AUDIO);

        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'application/json');
        $this->assertSame(
            ['text' => RecordingTranscriptionHttpClient::TRANSCRIPT],
            json_decode($client->getResponse()->getContent(), true),
        );
    }

    /**
     * The recording must reach the backend byte for byte: re-encoding is the one thing that could
     * silently degrade accuracy, and it would degrade it invisibly — a slightly worse transcript
     * looks exactly like a slightly worse model.
     */
    public function testTheRecordingReachesTheBackendUnaltered(): void
    {
        $client = static::createClient();
        $this->post($client, self::AUDIO);

        $sent = $this->transport()->lastRequest();

        $this->assertNotNull($sent, 'The endpoint must have called the backend.');
        $this->assertSame('POST', $sent['method']);
        $this->assertStringContainsString('/v1/speech-to-text', $sent['url']);
        $this->assertContains('xi-api-key: test-xi-key', $sent['options']['headers']);
        $this->assertStringContainsString(self::AUDIO, $this->transport()->lastBody(), 'The recording must not be re-encoded.');
        $this->assertStringContainsString('scribe_v2', $this->transport()->lastBody());
    }

    /**
     * `tag_audio_events` defaults to TRUE at the vendor, which decorates a transcript with
     * "(laughter)" / "(applause)" markers. The transcript goes straight into the composer as the
     * operator's words, so an event tag there is noise they would have to delete by hand — it must be
     * switched off explicitly, and stay off.
     */
    public function testAudioEventTaggingIsSwitchedOff(): void
    {
        $client = static::createClient();
        $this->post($client, self::AUDIO);

        $this->assertMatchesRegularExpression(
            '/name="tag_audio_events".*\s+false/s',
            $this->transport()->lastBody(),
        );
    }

    /**
     * Same posture as the voice: the browser already announces its language on every request, so the
     * transcription language is read there rather than added to the wire (CONTRACT.md). Pinning it
     * matters more here than for TTS — a French sentence transcribed as English does not come back
     * accented, it comes back as different words.
     */
    #[DataProvider('dictatedLocales')]
    public function testTheTranscriptionLanguageFollowsTheRequestLocale(string $acceptLanguage, string $expected): void
    {
        $client = static::createClient();
        $this->post($client, self::AUDIO, acceptLanguage: $acceptLanguage);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString($expected, $this->transport()->lastBody());
    }

    /** @return iterable<string, array{string, string}> */
    public static function dictatedLocales(): iterable
    {
        yield 'english' => ['en-GB,en;q=0.9', 'en'];
        yield 'french' => ['fr-FR,fr;q=0.9', 'fr'];
        // An unsupported language must not reach the backend as-is: it falls back to the app default.
        yield 'unsupported falls back to French' => ['de-DE', 'fr'];
    }

    /**
     * Silence is the commonest failure of a push-to-talk control — a mis-press, a thumb, a room too
     * loud — and it must cost one gesture and nothing else. `{text:''}` is a 200: the endpoint worked,
     * there were simply no words. The dashboard leaves the composer untouched (CHOREOGRAPHY § "La
     * dictée", the empty-transcript row) rather than printing a card to dismiss.
     */
    public function testSilenceIsAnEmptyTranscriptNotAnError(): void
    {
        $client = static::createClient();
        $this->transport()->transcript = '';
        $this->post($client, self::AUDIO);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(['text' => ''], json_decode($client->getResponse()->getContent(), true));
    }

    #[DataProvider('untranscribableBodies')]
    public function testAnUntranscribableBodyIsRefusedWithoutWakingTheBackend(string $body, string $contentType): void
    {
        $client = static::createClient();
        $this->post($client, $body, contentType: $contentType);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame([], $this->transport()->requests, 'A rejected body must not reach the backend.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function untranscribableBodies(): iterable
    {
        yield 'empty' => ['', self::AUDIO_TYPE];
        // 30 s of opus is ~90 KB (docs/dictation-plan.md § Phase 1); past 1 MB the thumb is on the
        // control, not on a sentence.
        yield 'over the cap' => [str_repeat('a', 1024 * 1024 + 1), self::AUDIO_TYPE];
        // The composer only ever posts what MediaRecorder produced. Anything else is a caller bug,
        // and handing a text body to a model that will dutifully hallucinate over it is worse than
        // refusing it.
        yield 'not audio at all' => ['transcris ceci', 'text/plain'];
    }

    /**
     * The bias list (docs/dictation-plan.md § keyterms): the operator's OWN garage is sent as
     * vocabulary, because the measured error class of any transcriber is exactly proper nouns — the
     * local route spelled the operator's Mazda "Masda" and heard « Clio » as « l'acquillot ».
     */
    public function testTheOwnersGarageIsSentAsBiasVocabulary(): void
    {
        $this->seedVehicle('MAZDA', 'MAZDA6', self::TEST_OWNER);
        $this->seedVehicle('RENAULT', 'CLIO', self::TEST_OWNER);

        $client = static::createClient();
        $this->post($client, self::AUDIO);

        $body = $this->transport()->lastBody();
        $this->assertStringContainsString('name="keyterms"', $body);
        foreach (['MAZDA', 'MAZDA6', 'RENAULT', 'CLIO'] as $term) {
            $this->assertStringContainsString($term, $body);
        }
    }

    /**
     * **Each term is its OWN `keyterms` field.** Measured against the live vendor on 2026-09-11, with
     * the same key the stack uses: the field is read as ONE keyword, so a JSON array arrives as a
     * single keyword spelled `["MAZDA","CLIO"]` and is refused —
     * `400 {"status":"invalid_keyword","message":"Some keyword contains invalid characters"}`, for the
     * WHOLE request, which the dashboard shows as a silent 503 and the operator as a mic that
     * transcribes nothing. The same probe with one plain value per field passes validation.
     *
     * That is why this asserts on the ENVELOPE and not merely that the terms appear somewhere in it:
     * the JSON version contained every term too, and shipped broken.
     */
    public function testEachBiasTermIsItsOwnFieldRatherThanAJsonArray(): void
    {
        $this->seedVehicle('MAZDA', 'MAZDA6', self::TEST_OWNER);
        $this->seedVehicle('RENAULT', 'CLIO', self::TEST_OWNER);

        $client = static::createClient();
        $this->post($client, self::AUDIO);

        $body = $this->transport()->lastBody();
        $expected = \count(static::getContainer()->get(GarageVocabulary::class)->terms(Locale::Fr));
        $this->assertGreaterThan(1, $expected, 'This test is meaningless with a single term.');
        $this->assertSame($expected, substr_count($body, 'name="keyterms"'), 'One multipart field per term.');
        // The characters a JSON envelope would introduce — and the ones the vendor names as invalid.
        $this->assertStringNotContainsString('["', $body);
        $this->assertStringNotContainsString('","', $body);
    }

    /**
     * The bias list turns `/stt` into an owner-SCOPED read — the one thing the endpoint did not do
     * before. Another tenant's vehicle names must never ride along on this owner's request: it would
     * leak their garage to the vendor AND bias this operator's words toward cars they do not own.
     */
    public function testAnotherOwnersGarageIsNeverSent(): void
    {
        $this->seedVehicle('MAZDA', 'MAZDA6', self::TEST_OWNER);
        $this->seedVehicle('LAMBORGHINI', 'HURACAN', 'someone-else');

        $client = static::createClient();
        $this->post($client, self::AUDIO);

        $body = $this->transport()->lastBody();
        $this->assertStringContainsString('MAZDA6', $body);
        $this->assertStringNotContainsString('LAMBORGHINI', $body);
        $this->assertStringNotContainsString('HURACAN', $body);
    }

    /**
     * `keyterms` carries a **+20 % credit surcharge whenever it is present**, so an empty garage must
     * not pay it: there is no vocabulary to bias toward, and the generic terms alone do not earn a
     * fifth of every bill. The field is omitted entirely, not sent empty.
     */
    public function testAnEmptyGarageSendsNoKeytermsAtAll(): void
    {
        $client = static::createClient();
        $this->post($client, self::AUDIO);

        $this->assertStringNotContainsString('name="keyterms"', $this->transport()->lastBody());
    }

    /**
     * A backend that refuses, dies or is simply not running must leave the operator typing — never a
     * 500 to the dashboard. Same degraded mode as the voice: silence, no card, no retry.
     */
    public function testABackendFailureIsSilentNotAServerError(): void
    {
        $client = static::createClient();
        $this->transport()->status = 503;
        $this->post($client, self::AUDIO);

        $this->assertResponseStatusCodeSame(503);
        // getContent() throws on a 5xx by default in API Platform's test client; `false` asks for the
        // body instead of an exception, which is the whole point here — the body must be EMPTY.
        $this->assertSame('', $client->getResponse()->getContent(false));
    }

    private function seedVehicle(string $make, string $model, string $owner): void
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        \assert($dm instanceof DocumentManager);
        $vehicle = new Vehicle($make, $model, VehicleType::Car, 'user');
        $vehicle->setOwner($owner);
        $dm->persist($vehicle);
        $dm->flush();
        $dm->clear();
    }

    private function post(
        object $client,
        string $body,
        bool $authed = true,
        string $contentType = self::AUDIO_TYPE,
        ?string $acceptLanguage = null,
    ): void {
        // Lowercase-dashed on purpose: API Platform's test Client folds every header name onto a
        // $_SERVER key, and its own default `content-type: application/ld+json` would otherwise win
        // the fold and the endpoint would never see the recording's real media type.
        $headers = ['content-type' => $contentType];
        if ($authed) {
            $headers += static::authHeaders();
        }
        if (null !== $acceptLanguage) {
            $headers += ['Accept-Language' => $acceptLanguage];
        }

        $client->request('POST', '/stt', ['body' => $body, 'headers' => $headers]);
    }

    private function transport(): RecordingTranscriptionHttpClient
    {
        return static::getContainer()->get(RecordingTranscriptionHttpClient::class);
    }
}
