<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Decorates the `catalog` firewall's access_token authenticator: the token is still fully validated
 * (and authenticates the request when valid), but a failure lets the request continue anonymously
 * instead of answering 401. The AuthenticatorManager treats a null failure response as "continue".
 */
#[AsDecorator(decorates: 'security.authenticator.access_token.catalog')]
final class LenientAccessTokenAuthenticator implements AuthenticatorInterface
{
    public function __construct(private readonly AuthenticatorInterface $inner)
    {
    }

    public function supports(Request $request): ?bool
    {
        return $this->inner->supports($request);
    }

    public function authenticate(Request $request): Passport
    {
        return $this->inner->authenticate($request);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return $this->inner->createToken($passport, $firewallName);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->inner->onAuthenticationSuccess($request, $token, $firewallName);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
