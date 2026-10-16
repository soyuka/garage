<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The access_token authenticator is not an entry point, so a request without a token on the
 * deny-by-default `api` firewall would get a bare 401. RFC 6750 §3 wants the Bearer challenge.
 */
final class BearerEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response(null, Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
