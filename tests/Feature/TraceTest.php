<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use TheAaronLawrence\Culprit\PathNormaliser;
use TheAaronLawrence\Culprit\Tests\Fixtures\Thrower;

final class TraceTest extends ReporterTestCase
{
    private const FIXTURE = 'tests/Fixtures/Thrower.php';

    public function test_the_payload_is_version_2_with_relative_paths(): void
    {
        $payload = $this->payloadFor(Thrower::make());

        $this->assertSame(2, $payload['payload_version']);
        $this->assertSame(RuntimeException::class, $payload['class']);
        $this->assertSame(self::FIXTURE, $payload['file']);
        $this->assertSame(self::lineOf(base_path(self::FIXTURE), 'MARKER:THROW'), $payload['line']);
    }

    public function test_the_base_path_is_stripped_from_the_message(): void
    {
        $payload = $this->payloadFor(new RuntimeException('Missing '.base_path('app/Models/Product.php').' on disk'));

        $this->assertSame('Missing app/Models/Product.php on disk', $payload['message']);
    }

    public function test_a_base_path_of_app_does_not_double_strip(): void
    {
        $paths = new PathNormaliser('/app');

        $this->assertSame('app/Http/Kernel.php', $paths->relative('/app/app/Http/Kernel.php'));
        $this->assertSame('vendor/foo/app/src/A.php', $paths->relative('/app/vendor/foo/app/src/A.php'));
        $this->assertSame('/usr/share/php/Foo.php', $paths->relative('/usr/share/php/Foo.php'));
        $this->assertSame('/application/app/A.php', $paths->relative('/application/app/A.php'));
        $this->assertSame('phar:///app/tool.phar/src/A.php', $paths->relative('phar:///app/tool.phar/src/A.php'));
    }

    public function test_the_first_frame_is_where_the_exception_was_thrown(): void
    {
        $payload = $this->payloadFor(Thrower::make());
        $frame = $payload['trace'][0];

        $this->assertSame($payload['file'], $frame['file']);
        $this->assertSame($payload['line'], $frame['line']);
        $this->assertSame(Thrower::class, $frame['class']);
        $this->assertSame('throw', $frame['function']);
        $this->assertFalse($frame['is_vendor']);
        $this->assertArrayNotHasKey('args', $frame);
    }

    public function test_frames_are_marked_as_vendor_or_app(): void
    {
        $trace = $this->payloadFor(Thrower::make())['trace'];

        $vendor = array_filter($trace, fn (array $frame): bool => str_starts_with((string) $frame['file'], 'vendor/'));
        $this->assertNotEmpty($vendor);
        foreach ($vendor as $frame) {
            $this->assertTrue($frame['is_vendor']);
        }

        $last = $trace[array_key_last($trace)];
        $this->assertSame('{main}', $last['function']);
        $this->assertTrue($last['is_vendor']);
    }

    public function test_framework_entry_points_count_as_vendor(): void
    {
        $paths = new PathNormaliser('/app');

        $this->assertTrue($paths->isVendor('public/index.php', 'require_once'));
        $this->assertTrue($paths->isVendor('artisan', 'handleCommand'));
        $this->assertTrue($paths->isVendor('app/Foo.php', '{main}'));
        $this->assertTrue($paths->isVendor('vendor/laravel/framework/src/A.php', 'handle'));
        $this->assertTrue($paths->isVendor(null, 'array_map'));
        $this->assertFalse($paths->isVendor('app/Http/Controllers/CheckoutController.php', 'store'));
    }

    public function test_identical_consecutive_frames_collapse_with_a_repeat_count(): void
    {
        $trace = $this->payloadFor(Thrower::recursion(300))['trace'];
        $callLine = self::lineOf(base_path(self::FIXTURE), 'MARKER:RECURSE_CALL');

        $repeated = array_values(array_filter($trace, fn (array $frame): bool => $frame['line'] === $callLine
            && $frame['file'] === self::FIXTURE));

        $this->assertCount(1, $repeated);
        $this->assertSame(300, $repeated[0]['repeat']);
        $this->assertArrayNotHasKey('repeat', $trace[0]);
    }

    public function test_long_traces_keep_the_first_50_and_last_20_frames(): void
    {
        $exception = Thrower::pingPong(200);
        $payload = $this->payloadFor($exception);
        $frames = count($exception->getTrace()) + 1;

        $this->assertCount(70, $payload['trace']);
        // Omitted = frames beyond the 70 kept, less any collapsed repeats.
        $this->assertGreaterThan(250, $payload['trace_omitted']);
        $this->assertLessThanOrEqual($frames - 70, $payload['trace_omitted']);
        $this->assertSame($payload['line'], $payload['trace'][0]['line']);
        $this->assertSame('{main}', $payload['trace'][69]['function']);
    }

    public function test_short_traces_omit_nothing(): void
    {
        $this->assertSame(0, $this->payloadFor(Thrower::make())['trace_omitted']);
    }

    public function test_previous_exceptions_are_sent_up_to_three_deep(): void
    {
        $payload = $this->payloadFor(Thrower::chain(5));

        $this->assertSame('Wrapper 5', $payload['message']);
        $this->assertCount(3, $payload['previous']);
        $this->assertSame('Wrapper 4', $payload['previous'][0]['message']);
        $this->assertSame('Wrapper 2', $payload['previous'][2]['message']);

        $previous = $payload['previous'][0];
        $this->assertSame(RuntimeException::class, $previous['class']);
        $this->assertSame(5, $previous['code']);
        $this->assertSame(self::FIXTURE, $previous['file']);
        $this->assertIsInt($previous['line']);
        $this->assertNotEmpty($previous['trace']);
        $this->assertArrayHasKey('is_vendor', $previous['trace'][0]);
    }

    public function test_no_previous_is_an_empty_list(): void
    {
        $this->assertSame([], $this->payloadFor(Thrower::make())['previous']);
    }

    public function test_anonymous_class_names_drop_the_nul_byte_and_absolute_path(): void
    {
        $exception = Thrower::anonymous();
        $this->assertStringContainsString("\0", $exception::class);

        $class = $this->payloadFor($exception)['class'];

        $this->assertSame('RuntimeException@anonymous '.self::FIXTURE, $class);
    }

    public function test_closure_function_names_use_relative_paths(): void
    {
        $exception = (function (): RuntimeException {
            return new RuntimeException('From a closure');
        })();

        $closureFrames = array_filter(
            $this->payloadFor($exception)['trace'],
            fn (array $frame): bool => str_starts_with($frame['function'], '{closure'),
        );

        $this->assertNotEmpty($closureFrames);
        foreach ($closureFrames as $frame) {
            $this->assertStringNotContainsString(base_path(), $frame['function']);
        }
    }

    public function test_query_exception_bindings_are_stripped_from_the_message(): void
    {
        $exception = new QueryException(
            'sqlite',
            'insert into "users" ("email", "password") values (?, ?)',
            ['priya@example.com', 'hash-value'],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email'),
        );

        $message = $this->payloadFor($exception)['message'];

        $this->assertStringNotContainsString('priya@example.com', $message);
        $this->assertStringNotContainsString('hash-value', $message);
        $this->assertStringContainsString('values (?, ?))', $message);
        $this->assertStringContainsString('UNIQUE constraint failed', $message);
    }

    public function test_query_exception_bindings_are_kept_when_the_option_is_off(): void
    {
        config(['culprit.strip_query_bindings' => false]);

        $exception = new QueryException('sqlite', 'select * from users where email = ?', ['priya@example.com'], new PDOException('boom'));

        $this->assertStringContainsString('priya@example.com', $this->payloadFor($exception)['message']);
    }

    public function test_long_messages_are_cut_at_64_kb_and_flagged(): void
    {
        $payload = $this->payloadFor(new RuntimeException(str_repeat('é', 100_000)));

        $this->assertLessThanOrEqual(65_536, strlen($payload['message']));
        $this->assertTrue(mb_check_encoding($payload['message'], 'UTF-8'));
        $this->assertTrue($payload['truncated']['message']);
    }

    public function test_nothing_is_flagged_as_truncated_for_a_small_report(): void
    {
        $this->assertSame([], $this->payloadFor(Thrower::make())['truncated']);
    }
}
