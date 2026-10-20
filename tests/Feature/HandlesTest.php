<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use TheAaronLawrence\Culprit\Culprit;
use TheAaronLawrence\Culprit\Facades\Culprit as CulpritFacade;

final class HandlesTest extends ReporterTestCase
{
    private function freshHandler(): Handler
    {
        return new Handler(app());
    }

    public function test_handles_reports_and_laravel_still_logs(): void
    {
        $handler = $this->freshHandler();
        app(Culprit::class)->handles(new Exceptions($handler));

        $log = Log::spy();

        $handler->report(new RuntimeException('Handled'));

        $this->assertCount(1, $this->sentPayloads());
        $this->assertSame('Handled', $this->lastSentPayload()['message']);
        $log->shouldHaveReceived('error')->once();
    }

    public function test_the_facade_resolves_and_handles(): void
    {
        $handler = $this->freshHandler();
        CulpritFacade::handles(new Exceptions($handler));

        $handler->report(new RuntimeException('Via facade'));

        $this->assertSame('Via facade', $this->lastSentPayload()['message']);
    }
}
