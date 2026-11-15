<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Tests\Mock\FakeRegistrationDocumentReader;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class RegistrationDocumentExtractTest extends AppTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x9a\xa0\xa0\x00\x00\x00\x00IEND\xaeB`\x82";

    public function testAnonymousIsRefusedBeforeAnyModelCall(): void
    {
        $client = static::createAnonymousClient();
        $this->post($client, self::PNG, 'image/png', authed: false);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(0, $this->reader()->calls);
    }

    public function testExtractsTheWhitelistedFields(): void
    {
        $client = static::createClient();
        $this->post($client, self::PNG, 'image/png');

        $this->assertResponseStatusCodeSame(200);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AB-123-CD', $body['A']);
        $this->assertSame('2004-09-15', $body['B']);
        $this->assertSame('PEUGEOT', $body['D.1']);
        $this->assertNull($body['D.3']);
        $this->assertTrue($body['legible']);
        $this->assertSame([], $body['warnings']);
        $this->assertSame(self::PNG, $this->reader()->lastContent);
        $this->assertSame('image/png', $this->reader()->lastMimeType);
    }

    public function testResponseHasNoHolderDataEvenIfTheModelReturnsIt(): void
    {
        $client = static::createClient();
        $this->reader()->response += ['C.1' => 'DUPONT JEAN', 'C.4.1' => '12 RUE DES LILAS', 'C.1.1' => 'DUPONT'];
        $this->post($client, self::PNG, 'image/png');

        $this->assertResponseStatusCodeSame(200);
        $raw = $client->getResponse()->getContent();
        $body = json_decode($raw, true);
        $this->assertSame([], array_filter(array_keys($body), static fn (int|string $k): bool => str_starts_with((string) $k, 'C')));
        $this->assertStringNotContainsString('DUPONT', $raw);
        $this->assertStringNotContainsString('LILAS', $raw);
    }

    public function testInvalidVinBecomesAWarning(): void
    {
        $client = static::createClient();
        $this->reader()->response['E'] = 'VF33CKFWB8347';
        $this->post($client, self::PNG, 'image/png');

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(['VIN invalide (somme de contrôle)'], $body['warnings']);
    }

    public function testRefusesAnUnsupportedType(): void
    {
        $client = static::createClient();
        $this->post($client, 'GIF89a', 'image/gif');

        $this->assertResponseStatusCodeSame(415);
        $this->assertSame(0, $this->reader()->calls);
    }

    public function testRefusesAMissingFile(): void
    {
        $client = static::createClient();
        $client->request('POST', '/registration-documents/extract', ['headers' => static::authHeaders()]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testRefusesAFileOverTenMegabytes(): void
    {
        // The test client's error rendering copies the request body several times over.
        $this->raiseMemoryLimit('512M');
        $client = static::createClient();
        $this->post($client, self::PNG.str_repeat("\0", 10 * 1024 * 1024), 'image/png');

        $this->assertResponseStatusCodeSame(413);
        $this->assertSame(0, $this->reader()->calls);
    }

    public function testRefusesAnImageOverFourMegabytes(): void
    {
        $this->raiseMemoryLimit('512M');
        $client = static::createClient();
        $this->post($client, self::PNG.str_repeat("\0", 4 * 1024 * 1024), 'image/png');

        $this->assertResponseStatusCodeSame(413);
        $this->assertSame(0, $this->reader()->calls);
    }

    public function testAPdfMayExceedTheImageCap(): void
    {
        $this->raiseMemoryLimit('512M');
        $client = static::createClient();
        $this->post($client, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R>>endobj\n".str_repeat(' ', 5 * 1024 * 1024)."trailer<</Root 1 0 R>>\n%%EOF", 'application/pdf');

        $this->assertResponseStatusCodeSame(200);
    }

    public function testRefusesAPdfOverTwoPages(): void
    {
        $client = static::createClient();
        $this->post($client, "%PDF-1.4\n2 0 obj<</Type/Pages/Count 3>>endobj\n".str_repeat("<</Type/Page/Parent 2 0 R>>\n", 3).'%%EOF', 'application/pdf');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->reader()->calls);
    }

    public function testRefusesAPdfWhosePagesCannotBeCounted(): void
    {
        $client = static::createClient();
        $this->post($client, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF", 'application/pdf');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->reader()->calls);
    }

    public function testIsRateLimitedPerOwner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $factory = static::getContainer()->get('limiter.intake');
        $this->assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create(self::TEST_OWNER);
        for ($i = 0; $i < 10_000; ++$i) {
            if (!$limiter->consume()->isAccepted()) {
                break;
            }
        }

        $this->post($client, self::PNG, 'image/png');

        $this->assertResponseStatusCodeSame(429);
        $this->assertNotNull($client->getResponse()->getHeaders(false)['retry-after'][0] ?? null);
        $this->assertSame(0, $this->reader()->calls);
    }

    private function post(Client $client, string $bytes, string $mime, bool $authed = true): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cg');
        file_put_contents($path, $bytes);

        $client->request('POST', '/registration-documents/extract', [
            'headers' => ($authed ? static::authHeaders() : []) + ['content-type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => new UploadedFile($path, 'carte-grise', $mime, null, true)]],
        ]);
    }

    private function reader(): FakeRegistrationDocumentReader
    {
        return static::getContainer()->get(FakeRegistrationDocumentReader::class);
    }
}
