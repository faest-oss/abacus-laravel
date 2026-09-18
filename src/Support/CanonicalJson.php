<?php

declare(strict_types=1);

namespace Faest\Abacus\Support;

use InvalidArgumentException;
use JsonException;

final class CanonicalJson
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), self::FLAGS);
    }

    /**
     * @throws JsonException
     */
    public static function normalize(mixed $value): mixed
    {
        // Reject recursive arrays and invalid JSON scalars before walking them.
        json_encode($value, self::FLAGS);

        return self::normalizeValue($value);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function normalizeArray(array $value): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = self::normalize($value);

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $nested) {
                $normalized[$key] = self::normalizeValue($nested);
            }

            if (! array_is_list($normalized)) {
                ksort($normalized, SORT_STRING);
            }

            return $normalized;
        }

        if (is_int($value) || is_float($value) || is_string($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Payloads may contain only arrays and JSON scalar values.');
    }
}
