<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use TheAaronLawrence\Culprit\Tests\Fixtures\User;

final class UserAndAppTest extends ReporterTestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadSkeletonMigrations();
    }

    public function test_without_a_resolved_user_no_user_is_sent_and_no_query_runs(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->payloadFor(new RuntimeException('No user'));

        $this->assertNull($payload['user']);
        $this->assertSame(0, $queries);
    }

    public function test_the_user_id_is_sent_without_personal_details_by_default(): void
    {
        $user = User::query()->create(['name' => 'Priya Shah', 'email' => 'priya@example.com', 'password' => 'password']);
        $this->actingAs($user);

        $this->assertSame(['id' => $user->id], $this->payloadFor(new RuntimeException('With user'))['user']);
    }

    public function test_name_and_email_are_sent_when_opted_in(): void
    {
        config(['culprit.send_user_details' => true]);
        $user = User::query()->create(['name' => 'Priya Shah', 'email' => 'priya@example.com', 'password' => 'password']);
        $this->actingAs($user);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame(
            ['id' => $user->id, 'name' => 'Priya Shah', 'email' => 'priya@example.com'],
            $this->payloadFor(new RuntimeException('With user'))['user'],
        );
        $this->assertSame(0, $queries);
    }

    public function test_app_details_and_drivers_come_from_config(): void
    {
        config([
            'app.name' => 'Sample',
            'app.url' => 'http://sample-app.test',
            'app.debug' => true,
            'app.timezone' => 'UTC',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
        ]);

        $payload = $this->payloadFor(new RuntimeException('App details'));
        $app = $payload['app'];

        $this->assertSame('testing', $payload['environment']);
        $this->assertSame('Sample', $app['name']);
        $this->assertSame('testing', $app['environment']);
        $this->assertSame('http://sample-app.test', $app['url']);
        $this->assertTrue($app['debug']);
        $this->assertSame('en', $app['locale']);
        $this->assertSame('UTC', $app['timezone']);
        $this->assertSame(app()->version(), $app['laravel_version']);
        $this->assertSame(PHP_VERSION, $app['php_version']);
        $this->assertSame(base_path(), $app['base_path']);
        $this->assertSame(['database' => 'sqlite', 'cache' => 'array', 'queue' => 'sync', 'session' => 'array'], $app['drivers']);
        $this->assertSame(['hostname', 'os', 'sapi', 'peak_memory'], array_keys($app['server']));
        $this->assertSame(PHP_SAPI, $app['server']['sapi']);
        $this->assertIsInt($app['server']['peak_memory']);
    }

    public function test_the_top_level_keys_match_the_v2_contract(): void
    {
        $payload = $this->payloadFor(new RuntimeException('Shape'));

        $this->assertSame(
            ['payload_version', 'class', 'message', 'code', 'file', 'line', 'environment', 'occurred_at',
                'trace', 'trace_omitted', 'previous', 'context', 'user', 'app', 'truncated'],
            array_keys($payload),
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload['occurred_at']);
    }
}
