<?php

declare(strict_types=1);

namespace Faest\Abacus\Support;

use JsonSerializable;

final class PayloadFingerprint
{
    /**
     * Generate a deterministic canonical JSON string for comparison or hashing.
     */
    public static function canonicalize(mixed $data): string
    {
        if ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        $sort = function (mixed &$item) use (&$sort): void {
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }

                foreach ($item as &$value) {
                    $sort($value);
                }
            }
        };

        $sort($data);

        return (string) json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Check if incoming draft payload matches existing database record.
     */
    public static function matches(mixed $incomingPayload, mixed $existingPayload): bool
    {
        return self::canonicalize($incomingPayload) === self::canonicalize($existingPayload);
    }
}
