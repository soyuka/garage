<?php

declare(strict_types=1);

namespace App\Exception;

/** Tool arguments the validator refused; the MCP layer answers it as an `invalid_input` tool error. */
final class InvalidToolInputException extends \InvalidArgumentException
{
}
