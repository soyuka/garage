<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * An upstream JSON response kept as native BSON, so it stays queryable and is read without an unserialize
 * step. BSON element names cannot be empty or hold a NUL byte (the driver refuses the whole write), and a
 * paid response must never be lost to that: such a payload is kept as its JSON text instead. Dots and
 * dollar signs inside nested keys are fine on MongoDB 5+ and stay native.
 */
final class BsonPayload
{
    /**
     * @param array<mixed> $data
     *
     * @return array{native: array<mixed>|null, json: string|null}
     */
    public static function encode(array $data): array
    {
        return self::storable($data)
            ? ['native' => $data, 'json' => null]
            : ['native' => null, 'json' => json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)];
    }

    /**
     * @param array<mixed>|null $native
     *
     * @return array<mixed>
     */
    public static function decode(?array $native, ?string $json): array
    {
        if (null !== $json) {
            return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        }

        return $native ?? [];
    }

    /**
     * @param array<mixed> $data
     */
    private static function storable(array $data): bool
    {
        foreach ($data as $key => $value) {
            if ('' === $key || str_contains((string) $key, "\0")) {
                return false;
            }
            if (\is_array($value) && !self::storable($value)) {
                return false;
            }
        }

        return true;
    }
}
