<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use TheAaronLawrence\Culprit\Exceptions\CulpritTestException;
use TheAaronLawrence\Culprit\PayloadEncoder;
use TheAaronLawrence\Culprit\PayloadFactory;
use TheAaronLawrence\Culprit\TrackerClient;

/**
 * Sends one test error straight to the tracker and says what happened.
 *
 * It does not go through ErrorReporter, so the dedupe and back-off never
 * hide the result.
 */
final class TestCommand extends Command
{
    protected $signature = 'culprit:test';

    protected $description = 'Send a test error to Culprit';

    public function handle(PayloadFactory $payloads, PayloadEncoder $encoder, TrackerClient $client): int
    {
        $key = config('culprit.key');
        $url = config('culprit.url');

        if (! is_string($key) || $key === '') {
            $this->components->error('CULPRIT_KEY is not set, so nothing was sent.');
            $this->line("Add your project's key to .env, then run php artisan config:clear.");

            return self::FAILURE;
        }

        if (! is_string($url) || $url === '') {
            $this->components->error('CULPRIT_URL is empty, so nothing was sent.');

            return self::FAILURE;
        }

        $body = $encoder->encode($payloads->make(new CulpritTestException));

        try {
            $response = $client->post($url, $key, $body);
        } catch (ConnectionException $exception) {
            $this->components->error("Could not reach {$url}: {$exception->getMessage()}");
            $this->line('Check CULPRIT_URL.');

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->components->info("Sent a test error to {$url}. It should show up in Culprit shortly.");

            return self::SUCCESS;
        }

        if ($response->status() === 401) {
            $this->components->error('Culprit rejected the key (401).');
            $this->line('Check CULPRIT_KEY.');

            return self::FAILURE;
        }

        $this->components->error("Culprit answered {$response->status()}.");
        $this->line(match ($response->status()) {
            413 => 'The report was too large for the tracker.',
            429 => 'The tracker is rate-limiting this project, try again shortly.',
            default => 'Check CULPRIT_URL points at your tracker, and look at its logs.',
        });

        return self::FAILURE;
    }
}
