<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A definitive miss: the store answered and has no such record.
 *
 * Deliberately not an HTTP exception. api-platform/mcp's Handler catches HttpExceptionInterface
 * around the provider and the processor and turns it into a JSON-RPC -32603 carrying the raw
 * message, which takes the miss away from {@see \App\Mcp\DomainErrorHandler} before it can answer
 * with a structured result. The state shim re-throws HTTP misses as this so they survive that far.
 */
final class RecordNotFoundException extends \RuntimeException
{
}
