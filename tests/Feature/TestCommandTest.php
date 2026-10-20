<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use TheAaronLawrence\Culprit\Exceptions\CulpritTestException;
use TheAaronLawrence\Culprit\ReportThrottle;

final class TestCommandTest extends ReporterTestCase
{
    private function culpritTest(): PendingCommand
    {
        $command = $this->artisan('culprit:test');

        if (! $command instanceof PendingCommand) {
            throw new RuntimeException('Expected a pending command.');
        }

        return $command;
    }

    public function test_it_says_when_no_key_is_set(): void
    {
        config(['culprit.key' => null]);

        $this->culpritTest()
            ->expectsOutputToContain('CULPRIT_KEY is not set')
            ->expectsOutputToContain("Add your project's key to .env, then run php artisan config:clear.")
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_says_when_the_url_is_empty(): void
    {
        config(['culprit.url' => '']);

        $this->culpritTest()
            ->expectsOutputToContain('CULPRIT_URL is empty')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_confirms_an_accepted_report(): void
    {
        $this->culpritTest()
            ->expectsOutputToContain('Sent a test error to https://tracker.test/api/errors')
            ->assertExitCode(0);
    }

    public function test_it_explains_a_rejected_key(): void
    {
        $this->trackerResponse = Http::response([], 401);

        $this->culpritTest()
            ->expectsOutputToContain('rejected the key (401)')
            ->expectsOutputToContain('CULPRIT_KEY')
            ->assertExitCode(1);
    }

    public function test_it_shows_other_statuses(): void
    {
        $this->trackerResponse = Http::response([], 429);

        $this->culpritTest()
            ->expectsOutputToContain('answered 429')
            ->assertExitCode(1);
    }

    public function test_it_explains_a_connection_failure(): void
    {
        $this->trackerResponse = fn () => throw new ConnectionException('timed out');

        $this->culpritTest()
            ->expectsOutputToContain('Could not reach https://tracker.test/api/errors')
            ->expectsOutputToContain('CULPRIT_URL')
            ->assertExitCode(1);
    }

    public function test_it_sends_the_test_exception(): void
    {
        $this->culpritTest()->assertExitCode(0);

        $payload = $this->lastSentPayload();

        $this->assertSame(CulpritTestException::class, $payload['class']);
        $this->assertSame('This is a test error from Culprit.', $payload['message']);
    }

    public function test_it_ignores_dedupe_and_back_off(): void
    {
        app(ReportThrottle::class)->backOff(60);

        $this->culpritTest()->assertExitCode(0);
        $this->culpritTest()->assertExitCode(0);

        $this->assertCount(2, $this->sentPayloads());
    }
}
