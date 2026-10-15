<?php

declare(strict_types=1);

namespace App\Agent\Exception;

/**
 * Thrown when the agent calls the SAME tool with the SAME arguments and it fails the SAME way too many
 * times in one turn — a model stuck in a loop on a call it cannot get right (e.g. a weak model that
 * keeps sending a wrong field name despite the corrective error result). It aborts the turn well before
 * the blunt `max_tool_calls` ceiling, so the loop does not
 * keep re-sending an ever-growing context for nothing.
 */
final class RepeatedToolFailureException extends \RuntimeException
{
    public function __construct(string $tool, int $attempts)
    {
        parent::__construct(\sprintf('Tool "%s" failed %d times with identical arguments — aborting the stuck turn.', $tool, $attempts));
    }
}
