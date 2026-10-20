<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Works out where the error happened (an HTTP request, a queued job or a
 * console command), who the user was, and any Laravel Context.
 *
 * Nothing here runs a query: the user is read only when the guard already
 * holds it, and only from the model's attributes.
 */
final class ContextCollector
{
    public function __construct(
        private readonly CurrentJob $job,
        private readonly CurrentCommand $command,
        private readonly RequestCollector $request,
        private readonly Redactor $redactor,
    ) {}

    public function type(): string
    {
        if ($this->job->exists()) {
            return 'job';
        }

        if ($this->command->exists() || $this->inConsoleProcess()) {
            return 'console';
        }

        return 'http';
    }

    /**
     * @return array{context: array<string, mixed>, truncated: array<string, true>}
     */
    public function collect(): array
    {
        return match ($this->type()) {
            'job' => ['context' => ['type' => 'job', 'job' => $this->job->context()], 'truncated' => []],
            'console' => ['context' => ['type' => 'console', 'command' => $this->command()], 'truncated' => []],
            default => $this->http(),
        };
    }

    /**
     * @return array{id: mixed, name?: mixed, email?: mixed}|null
     */
    public function user(): ?array
    {
        try {
            if (! Auth::hasUser()) {
                return null;
            }

            $user = Auth::user();
        } catch (Throwable) {
            return null;
        }

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $details = ['id' => $user->getAuthIdentifier()];

        if (config('culprit.send_user_details') === true && $user instanceof Model) {
            $attributes = $user->getAttributes();

            foreach (['name', 'email'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $details[$key] = $attributes[$key];
                }
            }
        }

        return $details;
    }

    /**
     * Laravel `Context` values (not hidden ones), redacted. Null when empty.
     *
     * @return array<array-key, mixed>|null
     */
    public function laravelContext(): ?array
    {
        try {
            $values = Context::all();
        } catch (Throwable) {
            return null;
        }

        return $values === [] ? null : $this->redactor->redact($values);
    }

    /**
     * @return array{context: array<string, mixed>, truncated: array<string, true>}
     */
    private function http(): array
    {
        $request = app('request');

        if (! $request instanceof Request) {
            return ['context' => ['type' => 'http', 'request' => null], 'truncated' => []];
        }

        ['request' => $details, 'truncated' => $truncated] = $this->request->collect($request);

        return ['context' => ['type' => 'http', 'request' => $details], 'truncated' => $truncated];
    }

    /**
     * @return array{name: string, arguments: string}
     */
    private function command(): array
    {
        $current = $this->command->current();

        if ($current === null) {
            $argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));
            $name = (string) ($argv[1] ?? '');
            $line = implode(' ', array_slice($argv, 1));
        } else {
            $name = $current['name'];
            // The input names the command itself, quoted when it has a colon: 'playground:fail' --password=…
            $quoted = preg_quote($name, '/');
            $arguments = (string) preg_replace('/^(?:\''.$quoted.'\'|"'.$quoted.'"|'.$quoted.')(?=\s|$)/', '', $current['input']);
            $line = trim($name.' '.trim($arguments));
        }

        return [
            'name' => $name,
            'arguments' => $this->redactor->commandLine(trim('php artisan '.$line)),
        ];
    }

    private function inConsoleProcess(): bool
    {
        return app()->runningInConsole() && ! app()->runningUnitTests();
    }
}
