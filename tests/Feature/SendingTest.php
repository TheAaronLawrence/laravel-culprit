<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use TheAaronLawrence\Culprit\Culprit;
use TheAaronLawrence\Culprit\ErrorReporter;
use TheAaronLawrence\Culprit\Tests\Fixtures\Thrower;

final class SendingTest extends ReporterTestCase
{
    private function reporter(): ErrorReporter
    {
        return app(ErrorReporter::class);
    }

    public function test_the_report_is_posted_as_json_with_the_bearer_token(): void
    {
        $this->reporter()->report(new RuntimeException('Sent'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::TRACKER_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['message'] === 'Sent'
            && $request['payload_version'] === 2);
    }

    public function test_nothing_is_sent_without_a_tracker_url(): void
    {
        config(['culprit.url' => null]);

        $this->reporter()->report(new RuntimeException('Nowhere'));

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_without_a_key(): void
    {
        config(['culprit.key' => null]);

        $this->reporter()->report(new RuntimeException('No key'));

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_key_is_empty(): void
    {
        config(['culprit.key' => '']);

        $this->reporter()->report(new RuntimeException('Empty key'));

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_url_is_empty(): void
    {
        config(['culprit.url' => '']);

        $this->reporter()->report(new RuntimeException('Empty URL'));

        Http::assertNothingSent();
    }

    public function test_the_default_url_is_used_when_none_is_set(): void
    {
        $this->assertSame('http://useculprit.test/api/errors', Culprit::DEFAULT_URL);
        $this->assertSame(Culprit::DEFAULT_URL, (require __DIR__.'/../../config/culprit.php')['url']);
    }

    public function test_sending_is_deferred_until_after_the_response(): void
    {
        $this->withDefer();

        $this->reporter()->report(new RuntimeException('Later'));
        Http::assertNothingSent();

        app(DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(1);
    }

    public function test_invalid_utf8_in_the_message_is_still_sent(): void
    {
        $this->reporter()->report(new RuntimeException("Bad byte \xB1\x31 in value"));

        $message = $this->lastSentPayload()['message'];

        $this->assertTrue(mb_check_encoding($message, 'UTF-8'));
        $this->assertStringStartsWith('Bad byte ', $message);
    }

    public function test_anonymous_exception_classes_are_sent_without_nul_bytes(): void
    {
        $this->reporter()->report(Thrower::anonymous());

        $class = json_decode($this->lastSentRequest()->body(), true)['class'];

        $this->assertStringNotContainsString("\0", $class);
        $this->assertStringStartsWith('RuntimeException@anonymous', $class);
    }

    public function test_the_same_error_within_10_seconds_is_sent_once(): void
    {
        $this->freezeTime();

        for ($i = 0; $i < 3; $i++) {
            $this->reporter()->report(new RuntimeException("Loop {$i}"));
        }
        Http::assertSentCount(1);
        $this->assertSame('Loop 0', $this->lastSentPayload()['message']);
    }

    public function test_the_same_error_after_10_seconds_is_sent_again(): void
    {
        $this->freezeTime();
        $make = fn (): RuntimeException => new RuntimeException('Same line');

        $this->reporter()->report($make());
        $this->reporter()->report($make());
        Http::assertSentCount(1);

        $this->travel(11)->seconds();
        $this->reporter()->report($make());
        Http::assertSentCount(2);
    }

    public function test_a_429_suppresses_sends_until_retry_after_passes(): void
    {
        $this->freezeTime();
        $this->trackerResponse = fn () => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30']);

        $this->reporter()->report(new RuntimeException('First'));
        $this->reporter()->report(new RuntimeException('Second'));
        Http::assertSentCount(1);

        $this->travel(31)->seconds();
        $this->reporter()->report(new RuntimeException('Third'));
        Http::assertSentCount(2);
    }

    public function test_a_413_backs_off_too(): void
    {
        $this->freezeTime();
        $this->trackerResponse = fn () => Http::response('', 413);

        $this->reporter()->report(new RuntimeException('Too big'));
        $this->reporter()->report(new RuntimeException('Next'));

        Http::assertSentCount(1);
    }

    public function test_a_timeout_does_not_throw(): void
    {
        $this->trackerResponse = fn () => throw new ConnectionException('Operation timed out after 2001 milliseconds');

        $this->reporter()->report(new RuntimeException('Tracker down'));

        $this->addToAssertionCount(1);
    }

    public function test_empty_object_fields_are_sent_as_json_objects(): void
    {
        $this->reporter()->report(new RuntimeException('Small'));

        $body = $this->lastSentRequest()->body();

        $this->assertStringContainsString('"truncated":{}', $body);
        $this->assertStringContainsString('"previous":[]', $body);
    }

    public function test_a_failure_while_building_the_payload_does_not_throw(): void
    {
        config(['culprit.redact' => 'not-a-list']);

        $this->reporter()->report(new RuntimeException('Broken config'));

        $this->addToAssertionCount(1);
    }
}
