<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bonneref;

use App\Bonneref\BonnerefServiceToken;
use App\Exception\NotConfiguredException;
use App\Exception\UpstreamException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BonnerefServiceTokenTest extends TestCase
{
    private const string ISSUER = 'https://casdoor.test';
    private const string TOKEN_ENDPOINT = 'https://casdoor.test/api/login/oauth/access_token';
    private const string CLIENT_ID = 'mpa-service-id';
    // @mago-expect lint:no-literal-password test fixture
    private const string CLIENT_SECRET = 's3cr3t-value';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    private int $minted = 0;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-15 10:00:00');
    }

    public function testItMintsWithClientCredentialsAtTheDiscoveredTokenEndpoint(): void
    {
        $this->assertSame('service-token-1', $this->provider()->get());

        $this->assertSame(['GET', self::ISSUER.'/.well-known/openid-configuration'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        $this->assertSame(['POST', self::TOKEN_ENDPOINT], [$this->requests[1]['method'], $this->requests[1]['url']]);
        parse_str($this->requests[1]['body'], $form);
        $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET], $form);
    }

    public function testTheTokenIsReusedUntilShortlyBeforeItExpires(): void
    {
        $provider = $this->provider(expiresIn: 3600);

        $this->assertSame('service-token-1', $provider->get());
        $this->clock->sleep(3600 - BonnerefServiceToken::EXPIRY_MARGIN - 1);
        $this->assertSame('service-token-1', $provider->get());
        $this->assertSame(1, $this->minted);

        $this->clock->sleep(2);
        $this->assertSame('service-token-2', $provider->get());
    }

    public function testAForgottenTokenIsMintedAgain(): void
    {
        $provider = $this->provider();
        $provider->get();

        $provider->forget();

        $this->assertSame('service-token-2', $provider->get());
    }

    #[DataProvider('unsetCredential')]
    public function testAnUnsetCredentialFailsClosedWithoutCallingCasdoor(string $clientId, #[\SensitiveParameter] string $clientSecret): void
    {
        try {
            $this->provider(clientId: $clientId, clientSecret: $clientSecret)->get();
            $this->fail('an unset credential must not reach bonneref');
        } catch (NotConfiguredException $e) {
            $this->assertStringContainsString('MPA_SERVICE_CLIENT_ID', $e->getMessage());
            $this->assertStringContainsString('MPA_SERVICE_CLIENT_SECRET', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsetCredential(): iterable
    {
        yield 'no client id' => ['', self::CLIENT_SECRET];
        yield 'no secret' => [self::CLIENT_ID, ''];
    }

    public function testARefusedCredentialIsDefinitiveAndNeverEchoesTheSecret(): void
    {
        $provider = $this->provider(tokenResponse: new JsonMockResponse(['error' => 'invalid_client', 'error_description' => 'client_secret '.self::CLIENT_SECRET.' is invalid'], ['http_code' => 400]));

        try {
            $provider->get();
            $this->fail('a refused credential must surface');
        } catch (UpstreamException $e) {
            $this->assertFalse($e->transient);
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $e->getMessage());
        }
    }

    public function testAnUnreachableCasdoorIsTransient(): void
    {
        $provider = $this->provider(tokenResponse: new MockResponse('', ['http_code' => 503]));

        try {
            $provider->get();
            $this->fail('an unreachable Casdoor must surface');
        } catch (UpstreamException $e) {
            $this->assertTrue($e->transient);
        }
    }

    private function provider(string $clientId = self::CLIENT_ID, #[\SensitiveParameter] string $clientSecret = self::CLIENT_SECRET, int $expiresIn = 604800, ?MockResponse $tokenResponse = null): BonnerefServiceToken
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($expiresIn, $tokenResponse): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : ''];

            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                return new JsonMockResponse(['issuer' => self::ISSUER, 'token_endpoint' => self::TOKEN_ENDPOINT]);
            }
            if (null !== $tokenResponse) {
                return $tokenResponse;
            }

            return new JsonMockResponse(['access_token' => 'service-token-'.++$this->minted, 'token_type' => 'Bearer', 'expires_in' => $expiresIn]);
        });

        return new BonnerefServiceToken($http, new ArrayAdapter(clock: $this->clock), self::ISSUER, $clientId, $clientSecret);
    }
}
