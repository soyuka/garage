<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ServiceTokenHandler;
use App\Tests\Functional\FakeOidcToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ServiceTokenHandlerTest extends TestCase
{
    private const string CLIENT_ID = 'test-mpa-service';
    private const string SUBJECT = 'admin/mpa-service';

    private RequestStack $requests;

    protected function setUp(): void
    {
        $this->requests = new RequestStack();
    }

    public function testTheServiceActsForTheOwnerItNames(): void
    {
        $badge = $this->handler()->getUserBadgeFrom($this->serviceToken(owner: 'driver@example.com'));

        $this->assertSame('driver@example.com', $badge->getUserIdentifier());
        $this->assertSame('driver@example.com', $badge->getAttributes()['email'] ?? null);
    }

    public function testAUserTokenGoesToTheUserHandlerAndTheOwnerHeaderIsIgnored(): void
    {
        $this->requests->push(Request::create('/makes', server: ['HTTP_X_BONNEREF_OWNER' => 'someone-else@example.com']));

        $badge = $this->handler()->getUserBadgeFrom(FakeOidcToken::for('u1', 'driver@example.com', [FakeOidcToken::ATELIER_CLIENT]));

        $this->assertSame('user-handler:driver@example.com', $badge->getUserIdentifier());
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('notTheService')]
    public function testATokenClaimingToBeTheServiceMustBeItExactly(array $claims): void
    {
        $this->expectException(BadCredentialsException::class);

        $this->handler()->getUserBadgeFrom($this->serviceToken('driver@example.com', $claims));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notTheService(): iterable
    {
        yield 'another subject' => [['sub' => 'admin/someone']];
        yield 'a user of the service application' => [['sub' => 'bonneref/driver']];
        yield 'audience is another client' => [['aud' => ['test-atelier-client']]];
        yield 'no audience' => [['aud' => []]];
    }

    #[DataProvider('badOwner')]
    public function testTheOwnerHeaderMustNameOneEmail(?string $owner): void
    {
        $this->expectException(BadCredentialsException::class);

        $this->handler()->getUserBadgeFrom($this->serviceToken($owner));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function badOwner(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'not an email' => ['admin'];
        yield 'two emails' => ['a@example.com,b@example.com'];
        yield 'too long' => [str_repeat('a', 250).'@example.com'];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function serviceToken(?string $owner, array $override = []): string
    {
        $server = null === $owner ? [] : ['HTTP_X_BONNEREF_OWNER' => $owner];
        $this->requests->push(Request::create('/makes', server: $server));

        return FakeOidcToken::service(self::CLIENT_ID, $override);
    }

    private function handler(): ServiceTokenHandler
    {
        $users = new class implements AccessTokenHandlerInterface {
            public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
            {
                $claims = (new FakeOidcTokenHandler('email'))->verify($accessToken);

                return new UserBadge('user-handler:'.(\is_string($claims['email'] ?? null) ? $claims['email'] : ''));
            }
        };

        return new ServiceTokenHandler(new FakeOidcTokenHandler('email'), $users, $this->requests, self::CLIENT_ID, self::SUBJECT);
    }
}
