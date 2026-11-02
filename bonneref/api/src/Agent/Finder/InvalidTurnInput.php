<?php

declare(strict_types=1);

namespace App\Agent\Finder;

/**
 * A structured reference in a turn request (vehicle, previous turn, on-screen result, intake) that the
 * server cannot vouch for. Refused with 422 before any model call, never passed on as text.
 */
final class InvalidTurnInput extends \InvalidArgumentException
{
}
