<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * The vendor refused (bad key, quota, rate limit) or could not be reached.
 *
 * Exists so a vendor outage cannot become a 500 on the dashboard: voice is garnish, the notice card
 * is the payload, and muting the app must lose no information. The
 * controller turns this into a 503 and the client stays silent about it — never a toast about a toast.
 */
final class SpeechUnavailable extends \RuntimeException
{
}
