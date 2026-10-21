<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Sends reported exceptions to the error tracking app.
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
        private readonly TrackerClient $client,
        private readonly PayloadEncoder $encoder,
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
        $token = config('culprit.key');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '' || $this->throttle->isBackingOff()) {
            return;
        }

        // Never report a failure to handle a Culprit report: it would loop.
        if (request()->headers->has(Culprit::REPORT_HEADER)) {
            return;
        }

        if (! $this->throttle->attempt($this->fingerprint($exception))) {
            return;
        }

        $body = $this->encoder->encode($this->payloads->make($exception));

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
            $response = $this->client->post($url, $token, $body);

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
}
