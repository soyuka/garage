<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CorsTest extends AppTestCase
{
    private ?string $previous = null;

    protected function tearDown(): void
    {
        if (null !== $this->previous) {
            $_ENV['CORS_ALLOW_ORIGIN'] = $this->previous;
            $_SERVER['CORS_ALLOW_ORIGIN'] = $_ENV['CORS_ALLOW_ORIGIN'];
        }

        parent::tearDown();
    }

    public function testTheBonnerefOriginIsAllowedOnceTheEnvNamesIt(): void
    {
        $this->allowOrigins('^https://(localhost|bonneref\.example)(:[0-9]+)?$');

        $this->preflight('https://bonneref.example', 'POST', '/garage/vehicles');

        $this->assertResponseHeaderSame('access-control-allow-origin', 'https://bonneref.example');
        $this->assertStringContainsStringIgnoringCase('Authorization', (string) self::lastResponse()->headers->get('access-control-allow-headers'));
    }

    public function testAnOriginTheEnvDoesNotNameIsNotAllowed(): void
    {
        $this->allowOrigins('^https://(localhost|bonneref\.example)(:[0-9]+)?$');

        $this->preflight('https://evil.example', 'GET', '/garage/vehicles');

        $this->assertResponseNotHasHeader('access-control-allow-origin');
    }

    public function testTheLocalDevOriginsStayAllowedByDefault(): void
    {
        $this->preflight('http://localhost:5174', 'GET', '/garage/vehicles');

        $this->assertResponseHeaderSame('access-control-allow-origin', 'http://localhost:5174');
    }

    private function allowOrigins(string $regex): void
    {
        $this->previous = $_SERVER['CORS_ALLOW_ORIGIN'];
        $_ENV['CORS_ALLOW_ORIGIN'] = $regex;
        $_SERVER['CORS_ALLOW_ORIGIN'] = $_ENV['CORS_ALLOW_ORIGIN'];
    }

    private function preflight(string $origin, string $method, string $path): void
    {
        static::createAnonymousClient()->request('OPTIONS', $path, ['headers' => [
            'Origin' => $origin,
            'Access-Control-Request-Method' => $method,
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ]]);
    }
}
