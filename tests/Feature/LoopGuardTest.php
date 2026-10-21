<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use TheAaronLawrence\Culprit\Culprit;
use TheAaronLawrence\Culprit\ErrorReporter;

/**
 * A tracker that reports its own errors must not report an error raised while
 * it handles a report, or a broken ingest endpoint would report to itself forever.
 */
final class LoopGuardTest extends ReporterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/_tracker-test/ingest', fn () => throw new RuntimeException('Ingest failed'));
    }

    public function test_reports_are_marked_with_the_culprit_header(): void
    {
        app(ErrorReporter::class)->report(new RuntimeException('Marked'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(Culprit::REPORT_HEADER, '1'));
    }

    public function test_an_error_while_handling_a_culprit_report_is_not_sent(): void
    {
        $this->post('/_tracker-test/ingest', [], [Culprit::REPORT_HEADER => '1'])->assertServerError();

        Http::assertNothingSent();
    }

    public function test_the_same_error_on_an_ordinary_request_is_still_sent(): void
    {
        $this->post('/_tracker-test/ingest')->assertServerError();

        $this->assertSame('Ingest failed', $this->lastSentPayload()['message']);
    }
}
