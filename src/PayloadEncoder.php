<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use stdClass;

/**
 * Turns a payload into the JSON body the tracker expects.
 */
final class PayloadEncoder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        // Objects in the contract stay objects on the wire when empty.
        if (($payload['truncated'] ?? null) === []) {
            $payload['truncated'] = new stdClass;
        }

        if (($payload['context']['request']['headers'] ?? null) === []) {
            $payload['context']['request']['headers'] = new stdClass;
        }

        return json_encode(
            $payload,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
