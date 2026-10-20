<?php

declare(strict_types=1);
use TheAaronLawrence\Culprit\Culprit;

return [

    /*
    |--------------------------------------------------------------------------
    | Tracker endpoint
    |--------------------------------------------------------------------------
    |
    | Where reports are sent, and the project's key (sent as a bearer token).
    | Nothing is sent when the URL is empty.
    |
    */

    'url' => env('CULPRIT_URL', Culprit::DEFAULT_URL),

    'key' => env('CULPRIT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Header, body, Laravel Context and command-option keys that contain any of
    | these strings (case-insensitive) have their value replaced by
    | "[redacted]". The tracker runs the same list again at ingest.
    | Query-string keys also use "redact_query".
    |
    */

    'redact' => [
        'password',
        'password_confirmation',
        'current_password',
        '_token',
        'token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-xsrf-token',
        'x-csrf-token',
        'php-auth-pw',
        'card',
        'cvv',
        'cvc',
        'iban',
        'ssn',
    ],

    'redact_query' => [
        'signature',
        'code',
        'state',
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal data and payload options
    |--------------------------------------------------------------------------
    */

    // Send the client IP address with HTTP context.
    'send_ip' => (bool) env('CULPRIT_SEND_IP', false),

    // Send the authenticated user's name and email (the ID is always sent).
    'send_user_details' => (bool) env('CULPRIT_SEND_USER_DETAILS', false),

    // Remove bound values from QueryException messages ("values (?)").
    'strip_query_bindings' => (bool) env('CULPRIT_STRIP_QUERY_BINDINGS', true),

    // Send source snippets for vendor frames as well as your own code.
    'vendor_source' => (bool) env('CULPRIT_VENDOR_SOURCE', false),

];
