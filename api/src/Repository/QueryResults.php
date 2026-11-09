<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * ODM query results come back untyped (a hydrated document, an array, a count, a cursor): these narrow them
 * to what a hydrated query on one document class returns, and fail loudly on anything else.
 */
final class QueryResults
{
    /**
     * @template T of object
     *
     * @param class-string<T>        $class
     * @param iterable<mixed, mixed> $results
     *
     * @return list<T>
     */
    public static function listOf(string $class, iterable $results): array
    {
        $documents = [];
        foreach ($results as $result) {
            $documents[] = self::of($class, $result);
        }

        return $documents;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function oneOf(string $class, mixed $result): ?object
    {
        return null === $result ? null : self::of($class, $result);
    }

    public static function count(mixed $result): int
    {
        if (!\is_int($result)) {
            throw new \UnexpectedValueException(\sprintf('A count query returned %s.', get_debug_type($result)));
        }

        return $result;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function of(string $class, mixed $result): object
    {
        if (!$result instanceof $class) {
            throw new \UnexpectedValueException(\sprintf('A %s query returned %s.', $class, get_debug_type($result)));
        }

        return $result;
    }
}
