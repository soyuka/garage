<?php

declare(strict_types=1);

namespace App\Exception;

final class BudgetExhaustedException extends \RuntimeException
{
    public static function forPartsApiMethod(string $method, int $limit): self
    {
        return new self(sprintf('Call budget exhausted for partsapi.ru method "%s" (%d calls). Raise PARTSAPI_CALL_LIMIT only if the upstream allowance permits it.', $method, $limit));
    }
}
