<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Mock\FakePhotoIntakeReader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PhotoIntakeTest extends AppTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x9a\xa0\xa0\x00\x00\x00\x00IEND\xaeB`\x82";

    public function testAnonymousIsRefusedBeforeAnyModelCall(): void
    {
        $client = static::createAnonymousClient();
        $this->post($client, self::PNG, 'image/png', authed: false);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(0, $this->reader()->calls);
    }

    public function testACarteGriseComesBackWithTheWhitelistedFieldsOnly(): void
    {
        $client = static::createClient();
        $this->reader()->response = [
            'kind' => 'carte_grise',
            'A' => 'ab123cd',
            'B' => '15/09/2004',
            'D.1' => 'PEUGEOT',
            'C.1' => 'DUPONT JEAN',
            'legible' => true,
            'warnings' => [],
            'part' => ['familyGuess' => null, 'printedReferences' => [], 'brandGuess' => null],
        ];
        $this->post($client, self::PNG, 'image/png');

        $this->assertResponseStatusCodeSame(200);
        $raw = $client->getResponse()->getContent();
        $body = json_decode($raw, true);
        $this->assertSame('carte_grise', $body['kind']);
        $this->assertSame('AB-123-CD', $body['registration']['A']);
        $this->assertSame('2004-09-15', $body['registration']['B']);
        $this->assertTrue($body['registration']['legible']);
        $this->assertNull($body['part']);
        $this->assertStringNotContainsString('DUPONT', $raw);
        $this->assertSame(self::PNG, $this->reader()->lastContent);
    }

    public function testACarteGriseSpeaksFrenchAndDropsFreeTextWarnings(): void
    {
        $client = static::createClient();
        $this->reader()->response = [
            'kind' => 'carte_grise',
            'A' => 'ab123cd',
            'E' => 'VF33CKFWB8347',
            'legible' => false,
            'warnings' => ['blurry', 'E: last characters hard to read'],
        ];
        $this->post($client, self::PNG, 'image/png');

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(['VIN invalide (somme de contrôle)', 'Photo floue : reprenez-la de face', 'Lecture incomplète, vérifiez les champs'], $body['registration']['warnings']);
    }

    public function testAPartPhotoComesBackWithItsPrintedReferences(): void
    {
        $client = static::createClient();
        $this->reader()->response = [
            'kind' => 'part',
            'legible' => true,
            'warnings' => [],
            'part' => ['familyGuess' => 'filtre à huile', 'printedReferences' => ['W 712/73', ' ', 'W 712/73'], 'brandGuess' => 'MANN-FILTER'],
        ];
        $this->post($client, self::PNG, 'image/png');

        $this->assertResponseStatusCodeSame(200);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('part', $body['kind']);
        $this->assertNull($body['registration']);
        $this->assertSame(['familyGuess' => 'filtre à huile', 'printedReferences' => ['W 712/73'], 'brandGuess' => 'MANN-FILTER'], $body['part']);
    }

    public function testAnythingElseIsOther(): void
    {
        $client = static::createClient();
        $this->reader()->response = ['kind' => 'cat picture', 'legible' => true, 'warnings' => []];
        $this->post($client, self::PNG, 'image/png');

        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('other', $body['kind']);
        $this->assertNull($body['registration']);
        $this->assertNull($body['part']);
    }

    public function testRefusesAPdf(): void
    {
        $client = static::createClient();
        $this->post($client, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF", 'application/pdf');

        $this->assertResponseStatusCodeSame(415);
        $this->assertSame(0, $this->reader()->calls);
    }

    private function post(object $client, string $bytes, string $mime, bool $authed = true): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($path, $bytes);

        $client->request('POST', '/photo-intake', [
            'headers' => ($authed ? static::authHeaders() : []) + ['content-type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => new UploadedFile($path, 'photo', $mime, null, true)]],
        ]);
    }

    private function reader(): FakePhotoIntakeReader
    {
        return static::getContainer()->get(FakePhotoIntakeReader::class);
    }
}
