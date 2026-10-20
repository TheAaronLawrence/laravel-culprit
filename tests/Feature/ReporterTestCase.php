<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use TheAaronLawrence\Culprit\PayloadFactory;
use TheAaronLawrence\Culprit\Tests\TestCase;
use Throwable;

abstract class ReporterTestCase extends TestCase
{
    protected const TRACKER_URL = 'https://tracker.test/api/errors';

    /**
     * What the fake tracker answers: a response, or a closure that returns or throws.
     */
    protected mixed $trackerResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'culprit.url' => self::TRACKER_URL,
            'culprit.key' => 'secret-token',
        ]);

        Http::fake(fn () => value($this->trackerResponse) ?? Http::response(['message' => 'Accepted'], 202));

        // Deferred sends run straight away, unless a test turns defer back on.
        $this->withoutDefer();
    }

    /**
     * Laravel only fires CommandStarting/CommandFinished outside unit tests.
     */
    protected function fireCommandEvents(): void
    {
        $kernel = app(ConsoleKernel::class);

        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }
    }

    /**
     * The request context that was sent, without the source snippets (which
     * contain the test file's own literals), as JSON.
     */
    protected function sentContextJson(): string
    {
        return (string) json_encode($this->lastSentPayload()['context']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadFor(Throwable $exception): array
    {
        return app(PayloadFactory::class)->make($exception);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sentPayloads(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): array => $pair[0]->data())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastSentPayload(): array
    {
        $payloads = $this->sentPayloads();

        if ($payloads === []) {
            throw new RuntimeException('Nothing was sent to the tracker.');
        }

        return $payloads[array_key_last($payloads)];
    }

    protected function lastSentRequest(): Request
    {
        $last = Http::recorded()->last();

        if ($last === null) {
            throw new RuntimeException('Nothing was sent to the tracker.');
        }

        return $last[0];
    }

    /**
     * Line number of the first line in the file that contains the marker.
     */
    protected static function lineOf(string $file, string $marker): int
    {
        foreach (file($file) ?: [] as $index => $line) {
            if (str_contains($line, $marker)) {
                return $index + 1;
            }
        }

        throw new RuntimeException("Marker {$marker} not found in {$file}.");
    }
}
