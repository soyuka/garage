<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Metadata\Get;
use App\Dto\MotulOilInput;
use App\Entity\MaintenanceRecord;
use App\Entity\Vehicle;
use App\Garage\VehicleType;
use App\State\MotulOilProcessor;
use App\Tests\Mock\RecordingSpeechHttpClient;
use App\Tests\Mock\RecordingTranscriptionHttpClient;
use App\Tests\Mock\ScriptedStreamPlatform;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class OwnerRateLimitTest extends AppTestCase
{
    public function testTtsIsLimitedPerOwner(): void
    {
        $client = static::createClient();
        $this->exhaust('limiter.speech', self::TEST_OWNER);

        $response = $client->request('POST', '/tts', ['json' => ['text' => 'Vidange enregistrée.'], 'headers' => self::authHeaders()]);

        $this->assertRefused($response->getHeaders(false));
        $this->assertSame([], static::getContainer()->get(RecordingSpeechHttpClient::class)->requests, 'a refused line never reaches the vendor');
    }

    public function testSttIsLimitedPerOwner(): void
    {
        $client = static::createClient();
        $this->exhaust('limiter.transcription', self::TEST_OWNER);

        $response = $client->request('POST', '/stt', ['body' => 'RIFF....WAVE', 'headers' => ['content-type' => 'audio/webm'] + self::authHeaders()]);

        $this->assertRefused($response->getHeaders(false));
        $this->assertSame([], static::getContainer()->get(RecordingTranscriptionHttpClient::class)->requests);
    }

    public function testAnotherOwnerKeepsTheirOwnBudget(): void
    {
        $client = static::createClient();
        $this->exhaust('limiter.speech', 'someone-else@example.com');

        $client->request('POST', '/tts', ['json' => ['text' => 'Vidange enregistrée.'], 'headers' => self::authHeaders()]);

        $this->assertResponseIsSuccessful();
    }

    public function testDocumentUploadIsLimitedPerOwner(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $this->exhaust('limiter.upload', self::TEST_OWNER);

        $browser->request('POST', '/garage/vehicles/'.(string) $vehicle->getId().'/documents', ['kind' => 'manual'], ['file' => $this->pdf()], $this->bearer());

        $this->assertSame(429, $browser->getResponse()->getStatusCode());
        $this->assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
    }

    public function testBillUploadIsLimitedPerOwner(): void
    {
        $client = static::createClient();
        $browser = $client->getKernelBrowser();
        $vehicle = $this->seedVehicle();
        $dm = static::getContainer()->get(DocumentManager::class);
        $record = new MaintenanceRecord($dm->find(Vehicle::class, (string) $vehicle->getId()), [['type' => 'oil_change', 'parts' => []]], new \DateTimeImmutable('2026-01-01'), 'user');
        $dm->persist($record);
        $dm->flush();
        $this->exhaust('limiter.upload', self::TEST_OWNER);

        $browser->request('POST', '/garage/maintenance-records/'.(string) $record->getId().'/attachments', [], ['file' => $this->pdf()], $this->bearer());

        $this->assertSame(429, $browser->getResponse()->getStatusCode());
        $this->assertGreaterThan(0, (int) $browser->getResponse()->headers->get('Retry-After'));
    }

    public function testAgentTurnsHaveAGlobalBudget(): void
    {
        $client = static::createClient();
        $calls = 0;
        static::getContainer()->set('ai.platform.anthropic', new ScriptedStreamPlatform(static function () use (&$calls): string {
            ++$calls;

            return 'Fait.';
        }));
        $this->exhaust('limiter.agent_turn_global', 'global');

        $response = $client->request('POST', '/agent/turn', [
            'headers' => self::authHeaders() + ['Content-Type' => 'application/json'],
            'json' => ['instruction' => 'Quoi prévoir ?', 'sessionId' => 'sess-global'],
        ]);

        $this->assertRefused($response->getHeaders(false));
        $this->assertSame(0, $calls);
    }

    public function testTtsHasAGlobalBudget(): void
    {
        $client = static::createClient();
        $this->exhaust('limiter.speech_global', 'global');

        $response = $client->request('POST', '/tts', ['json' => ['text' => 'Vidange enregistrée.'], 'headers' => self::authHeaders()]);

        $this->assertRefused($response->getHeaders(false));
        $this->assertSame([], static::getContainer()->get(RecordingSpeechHttpClient::class)->requests);
    }

    public function testSttHasAGlobalBudget(): void
    {
        $client = static::createClient();
        $this->exhaust('limiter.transcription_global', 'global');

        $response = $client->request('POST', '/stt', ['body' => 'RIFF....WAVE', 'headers' => ['content-type' => 'audio/webm'] + self::authHeaders()]);

        $this->assertRefused($response->getHeaders(false));
        $this->assertSame([], static::getContainer()->get(RecordingTranscriptionHttpClient::class)->requests);
    }

    /**
     * Driven through the processor: an MCP call needs a session first, and the second request resets the
     * array-backed limiter the test exhausted. The MCP mapping of the refusal is DomainErrorHandlerTest's.
     */
    public function testMotulLookupsAreLimitedPerOwner(): void
    {
        static::createClient();
        $this->exhaust('limiter.motul', self::TEST_OWNER);
        $input = new MotulOilInput();
        $input->search = 'Mazda 6';

        $this->expectException(TooManyRequestsHttpException::class);
        static::getContainer()->get(MotulOilProcessor::class)->process($input, new Get());
    }

    private function exhaust(string $limiter, string $key): void
    {
        $factory = static::getContainer()->get($limiter);
        $this->assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $accepted = true;
        while ($accepted) {
            $accepted = $factory->create($key)->consume()->isAccepted();
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function assertRefused(array $headers): void
    {
        $this->assertResponseStatusCodeSame(429);
        $this->assertGreaterThan(0, (int) ($headers['retry-after'][0] ?? 0));
    }

    private function seedVehicle(): Vehicle
    {
        $dm = static::getContainer()->get(DocumentManager::class);
        $vehicle = new Vehicle('RENAULT', 'CLIO', VehicleType::Car, 'user', year: 2004);
        $vehicle->setOwner(self::TEST_OWNER);
        $dm->persist($vehicle);
        $dm->flush();

        return $vehicle;
    }

    private function pdf(): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mpa_rate_');
        file_put_contents($path, PdfFixture::renaultManual());

        return new UploadedFile($path, 'manual.pdf', 'application/pdf', test: true);
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function bearer(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.FakeOidcToken::for(self::TEST_OWNER, self::TEST_OWNER)];
    }
}
