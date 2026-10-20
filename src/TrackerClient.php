<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Posts an encoded payload to the tracker.
 */
final class TrackerClient
{
    /**
     * @throws ConnectionException
     */
    public function post(string $url, string $key, string $body): Response
    {
        return Http::connectTimeout(1)
            ->timeout(2)
            ->withToken($key)
            ->acceptJson()
            ->withBody($body, 'application/json')
            ->post($url);
    }
}
