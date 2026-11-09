<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Not a French plate (SIV or FNI) once normalised, refused before the quota and the paid SIV call.
 * Not an HTTP exception, like {@see RecordNotFoundException}, so the MCP tool answers `invalid_input`.
 */
final class InvalidPlateException extends \InvalidArgumentException
{
    public static function for(string $plate): self
    {
        return new self(\sprintf('"%s" is not a French registration plate (AB-123-CD, or 1234 AB 56 before 2009).', mb_substr($plate, 0, 20)));
    }
}
