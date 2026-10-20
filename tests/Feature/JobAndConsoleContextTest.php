<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use TheAaronLawrence\Culprit\CurrentCommand;
use TheAaronLawrence\Culprit\Tests\Fixtures\FailingJob;
use Throwable;

final class JobAndConsoleContextTest extends ReporterTestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadSkeletonMigrations();
    }

    public function test_a_failing_job_reports_job_context_without_its_payload(): void
    {
        try {
            dispatch(new FailingJob);
            $this->fail('The job should have thrown.');
        } catch (Throwable $exception) {
            $payload = $this->payloadFor($exception);
        }

        $this->assertSame('job', $payload['context']['type']);
        $job = $payload['context']['job'];

        $this->assertSame(FailingJob::class, $job['name']);
        $this->assertSame('sync', $job['connection']);
        $this->assertSame('sync', $job['queue']);
        $this->assertSame(1, $job['attempts']);
        $this->assertSame(1, $job['max_tries']);
        $this->assertArrayHasKey('id', $job);
        $this->assertIsString($job['uuid']);
        $this->assertSame(['name', 'connection', 'queue', 'attempts', 'max_tries', 'id', 'uuid'], array_keys($job));
    }

    public function test_a_finished_job_no_longer_counts_as_the_context(): void
    {
        dispatch(fn () => null);

        $this->assertSame('http', $this->payloadFor(new RuntimeException('After the job'))['context']['type']);
    }

    public function test_a_job_failing_in_a_database_queue_worker_is_sent(): void
    {
        config(['queue.default' => 'database']);
        dispatch(new FailingJob);

        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $payload = $this->lastSentPayload();
        $this->assertSame('Inventory sync failed for warehouse 7', $payload['message']);
        $this->assertSame('job', $payload['context']['type']);
        $this->assertSame('database', $payload['context']['job']['connection']);
        $this->assertSame(FailingJob::class, $payload['context']['job']['name']);
    }

    public function test_a_failing_command_reports_console_context_with_secrets_redacted(): void
    {
        $this->fireCommandEvents();

        try {
            Artisan::call('playground:fail', ['--password' => 'hunter2', '--warehouse' => '7']);
            $this->fail('The command should have thrown.');
        } catch (Throwable $exception) {
            $payload = $this->payloadFor($exception);
        }

        $this->assertSame('console', $payload['context']['type']);
        $command = $payload['context']['command'];

        $this->assertSame('playground:fail', $command['name']);
        $this->assertSame('php artisan playground:fail --password=[redacted] --warehouse=7', $command['arguments']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($payload['context']));
    }

    public function test_the_command_line_from_argv_names_the_command_once(): void
    {
        $command = app(CurrentCommand::class);
        $command->start('playground:fail', new ArgvInput(['artisan', 'playground:fail', '--password=hunter2', 'extra arg']));

        $this->assertSame(
            "php artisan playground:fail --password=[redacted] 'extra arg'",
            $this->payloadFor(new RuntimeException('Argv'))['context']['command']['arguments'],
        );
    }

    public function test_a_command_that_finished_no_longer_counts_as_the_context(): void
    {
        $this->fireCommandEvents();
        Artisan::call('inspire');

        $this->assertSame('http', $this->payloadFor(new RuntimeException('After the command'))['context']['type']);
    }

    public function test_laravel_context_is_sent_redacted_without_hidden_values(): void
    {
        Context::add('order_id', 7);
        Context::add('api_token', 'should-not-leave');
        Context::addHidden('internal', 'hidden-value');

        $payload = $this->payloadFor(new RuntimeException('With context'));

        $this->assertSame(['order_id' => 7, 'api_token' => '[redacted]'], $payload['laravel_context']);
    }

    public function test_laravel_context_is_left_out_when_empty(): void
    {
        $this->assertArrayNotHasKey('laravel_context', $this->payloadFor(new RuntimeException('No context')));
    }
}
