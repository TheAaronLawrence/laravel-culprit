<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Sends reported exceptions to the error tracking app.
 *
 * Lives in the sample app for now; it moves into package/ later.
 *
 * Reporting must never break the app or make an outage worse: every failure
 * is swallowed, HTTP requests send after the response has gone out, the same
 * error is sent once per 10 seconds, and 413/429 responses pause sending.
 */
final class ErrorReporter
{
    private bool $reporting = false;

    public function __construct(
        private readonly PayloadFactory $payloads,
        private readonly ReportThrottle $throttle,
        private readonly ContextCollector $context,
    ) {}

    public function report(Throwable $exception): void
    {
        // Something we call (a Blade source map, a log channel) may report too.
        if ($this->reporting) {
            return;
        }

        $this->reporting = true;

        try {
            $this->prepare($exception);
        } catch (Throwable) {
            //
        } finally {
            $this->reporting = false;
        }
    }

    private function prepare(Throwable $exception): void
    {
        $url = config('culprit.url');

        if (! is_string($url) || $url === '' || $this->throttle->isBackingOff()) {
            return;
        }

        if (! $this->throttle->attempt($this->fingerprint($exception))) {
            return;
        }

        $body = $this->encode($this->payloads->make($exception));
        $token = (string) config('culprit.key');

        $send = fn () => $this->send($url, $token, $body);

        // Jobs and commands send now: Laravel flushes deferred callbacks before
        // a worker or command reports its exception, so they would be lost.
        if ($this->context->type() === 'http') {
            defer($send, always: true);
        } else {
            $send();
        }
    }

    private function send(string $url, string $token, string $body): void
    {
        if ($this->throttle->isBackingOff()) {
            return;
        }

        try {
            $response = Http::connectTimeout(1)
                ->timeout(2)
                ->withToken($token)
                ->acceptJson()
                ->withBody($body, 'application/json')
                ->post($url);

            $this->handle($response);
        } catch (Throwable) {
            //
        }
    }

    private function handle(Response $response): void
    {
        if (! in_array($response->status(), [413, 429], true)) {
            return;
        }

        $retryAfter = $response->header('Retry-After');

        $this->throttle->backOff(is_numeric($retryAfter) ? (int) $retryAfter : null);
    }

    /**
     * Local dedupe key: the same class thrown from the same place.
     */
    private function fingerprint(Throwable $exception): string
    {
        return sha1($exception::class.'|'.$exception->getFile().'|'.$exception->getLine());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
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
