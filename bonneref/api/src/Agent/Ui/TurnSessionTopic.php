<?php

declare(strict_types=1);

namespace App\Agent\Ui;

use App\Agent\Finder\TurnInputLimits;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Mercure topic and cancel key of a turn session. The session id comes from the browser, so it is
 * bound to the owner: the same id posted by another owner lands on another topic and raises another
 * cancel flag. {@see TurnInputLimits::SESSION_ID_PATTERN} keeps `|` out of the id, so the HMAC input is
 * unambiguous. Same shape as mpa's DashboardChannel::sessionKey().
 */
final readonly class TurnSessionTopic
{
    public function __construct(
        // The public origin, never the request host: behind the proxy that can be http://php.
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUri,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public static function isValidId(string $sessionId): bool
    {
        return 1 === preg_match(TurnInputLimits::SESSION_ID_PATTERN, $sessionId);
    }

    public function key(string $owner, string $sessionId): string
    {
        return hash_hmac('sha256', 'session|'.$owner.'|'.$sessionId, $this->secret);
    }

    public function topic(string $owner, string $sessionId): string
    {
        return rtrim($this->baseUri, '/').'/agent/sessions/'.$this->key($owner, $sessionId);
    }
}
