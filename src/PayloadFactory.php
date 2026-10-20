<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Builds the payload v2 array for one exception.
 *
 * Every string is valid UTF-8 without NUL bytes, paths under base_path() are
 * relative, and sensitive values are already redacted.
 */
final class PayloadFactory
{
    public const VERSION = 2;

    public const MAX_MESSAGE_BYTES = 65536;

    public const MAX_PREVIOUS = 3;

    public function __construct(
        private readonly TraceBuilder $traces,
        private readonly PathNormaliser $paths,
        private readonly ContextCollector $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function make(Throwable $exception): array
    {
        $truncated = [];
        ['context' => $context, 'truncated' => $contextTruncated] = $this->context->collect();

        $payload = [
            'payload_version' => self::VERSION,
            ...$this->exception($exception, $truncated),
            'environment' => app()->environment(),
            'occurred_at' => now()->toIso8601String(),
        ];

        $payload = $this->moveTraceAfterBasics($payload);
        $payload['previous'] = $this->previous($exception);
        $payload['context'] = $context;

        $laravelContext = $this->context->laravelContext();
        if ($laravelContext !== null) {
            $payload['laravel_context'] = $laravelContext;
        }

        $payload['user'] = $this->context->user();
        $payload['app'] = $this->app();
        $payload['truncated'] = [...$truncated, ...$contextTruncated];

        return $this->scrub($payload);
    }

    /**
     * Class, message, code, file, line, trace and trace_omitted.
     *
     * @param  array<string, true>  $truncated
     * @return array<string, mixed>
     */
    private function exception(Throwable $exception, array &$truncated = []): array
    {
        $location = $this->traces->location($exception);
        $built = $this->traces->build($exception);

        return [
            'class' => $this->paths->className($exception::class),
            'message' => $this->message($exception, $truncated),
            'code' => $exception->getCode(),
            'file' => $this->paths->relative($location['file']),
            'line' => $location['line'],
            'trace' => $built['trace'],
            'trace_omitted' => $built['trace_omitted'],
        ];
    }

    /**
     * Keep the documented key order: trace fields come after occurred_at.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function moveTraceAfterBasics(array $payload): array
    {
        $trace = ['trace' => $payload['trace'], 'trace_omitted' => $payload['trace_omitted']];
        unset($payload['trace'], $payload['trace_omitted']);

        return [...$payload, ...$trace];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previous(Throwable $exception): array
    {
        $previous = [];
        $current = $exception->getPrevious();

        while ($current !== null && count($previous) < self::MAX_PREVIOUS) {
            $details = $this->exception($current);
            unset($details['trace_omitted']);
            $previous[] = $details;
            $current = $current->getPrevious();
        }

        return $previous;
    }

    /**
     * @param  array<string, true>  $truncated
     */
    private function message(Throwable $exception, array &$truncated): string
    {
        $message = $exception->getMessage();

        if ($exception instanceof QueryException && config('culprit.strip_query_bindings', true) === true) {
            $message = $this->withoutBindings($exception, $message);
        }

        $message = $this->paths->inText($this->clean($message));

        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            $message = mb_strcut($message, 0, self::MAX_MESSAGE_BYTES, 'UTF-8');
            $truncated['message'] = true;
        }

        return $message;
    }

    /**
     * QueryException messages end with ", SQL: <sql with bindings>)". Put the
     * placeholders back.
     */
    private function withoutBindings(QueryException $exception, string $message): string
    {
        $stripped = preg_replace('/, SQL: .*\)$/s', ', SQL: '.addcslashes($exception->getSql(), '\\$').')', $message, 1);

        return is_string($stripped) ? $stripped : $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function app(): array
    {
        $database = config('database.default');

        return [
            'name' => config('app.name'),
            'environment' => app()->environment(),
            'url' => config('app.url'),
            'debug' => (bool) config('app.debug'),
            'locale' => app()->getLocale(),
            'timezone' => config('app.timezone'),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'base_path' => base_path(),
            'drivers' => [
                'database' => is_string($database) ? config("database.connections.{$database}.driver", $database) : null,
                'cache' => config('cache.default'),
                'queue' => config('queue.default'),
                'session' => config('session.driver'),
            ],
            'server' => [
                'hostname' => gethostname() ?: null,
                'os' => PHP_OS_FAMILY,
                'sapi' => PHP_SAPI,
                'peak_memory' => memory_get_peak_usage(true),
            ],
        ];
    }

    /**
     * Valid UTF-8, no NUL bytes, in every string key and value.
     */
    private function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->clean($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $scrubbed = [];
        foreach ($value as $key => $item) {
            $scrubbed[is_string($key) ? $this->clean($key) : $key] = $this->scrub($item);
        }

        return $scrubbed;
    }

    private function clean(string $value): string
    {
        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }
}
