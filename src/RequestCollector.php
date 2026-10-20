<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Throwable;

/**
 * Describes the current HTTP request: route, headers, cookie names and body,
 * redacted and capped. Reads only what is already in memory.
 */
final class RequestCollector
{
    public const MAX_HEADERS = 64;

    public const MAX_HEADER_BYTES = 2048;

    public const MAX_BODY_BYTES = 16384;

    public const MAX_BODY_DEPTH = 5;

    public const TRUNCATED = '[truncated]';

    /** @var array{headers?: true, body?: true} */
    private array $truncated = [];

    public function __construct(private readonly Redactor $redactor) {}

    /**
     * @return array{request: array<string, mixed>, truncated: array{headers?: true, body?: true}}
     */
    public function collect(Request $request): array
    {
        $this->truncated = [];
        $route = $request->route();
        $route = $route instanceof Route ? $route : null;

        $context = [
            'method' => $request->method(),
            'url' => $this->url($request, $route),
            'route' => $route === null ? null : $this->route($route),
        ];

        if (config('culprit.send_ip') === true) {
            $context['ip'] = $request->ip();
        }

        $context['headers'] = $this->headers($request);
        $context['cookies'] = array_values(array_map('strval', $request->cookies->keys()));
        $context['body'] = $this->body($request);

        return ['request' => $context, 'truncated' => $this->truncated];
    }

    private function url(Request $request, ?Route $route): string
    {
        // The URL as sent (fullUrl() re-sorts and re-encodes the query string).
        $url = $request->getSchemeAndHttpHost().$request->getRequestUri();

        // Secrets in the path, such as /reset-password/{token}.
        foreach ($route?->parameters() ?? [] as $name => $value) {
            if (is_string($value) && $value !== '' && $this->redactor->isSensitive($name)) {
                $url = str_replace('/'.rawurlencode($value), '/'.Redactor::REDACTED, $url);
            }
        }

        return $this->redactor->url($url);
    }

    /**
     * @return array{name: string|null, uri: string, action: string, middleware: list<string>}
     */
    private function route(Route $route): array
    {
        try {
            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        } catch (Throwable) {
            $middleware = [];
        }

        return [
            'name' => $route->getName(),
            'uri' => $route->uri(),
            'action' => $route->getActionName(),
            'middleware' => $middleware,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (count($headers) >= self::MAX_HEADERS) {
                $this->truncated['headers'] = true;
                break;
            }

            $value = implode(', ', array_map('strval', array_filter((array) $values, fn (mixed $v): bool => $v !== null)));

            if (strlen($value) > self::MAX_HEADER_BYTES) {
                $value = mb_strcut($value, 0, self::MAX_HEADER_BYTES, 'UTF-8');
                $this->truncated['headers'] = true;
            }

            $headers[(string) $name] = $value;
        }

        return $this->redactor->redact($headers);
    }

    /**
     * @return array{kind: string, size: int, truncated: bool, data: array<array-key, mixed>|null}|null
     */
    private function body(Request $request): ?array
    {
        $files = $request->allFiles();
        $size = $this->size($request);

        if ($files !== []) {
            $kind = 'files';
            $data = $this->withFiles($request->request->all(), $files);
        } elseif ($request->isJson()) {
            $kind = 'json';
            $data = $request->json()->all();
        } elseif ($request->request->count() > 0) {
            $kind = 'form';
            $data = $request->request->all();
        } elseif ($size > 0) {
            return ['kind' => 'raw', 'size' => $size, 'truncated' => false, 'data' => null];
        } else {
            return null;
        }

        $truncated = false;
        $data = $this->capDepth($this->redactor->redact($data), 1, $truncated);
        $data = $this->capSize($data, $truncated);

        if ($truncated) {
            $this->truncated['body'] = true;
        }

        return ['kind' => $kind, 'size' => $size, 'truncated' => $truncated, 'data' => $data];
    }

    private function size(Request $request): int
    {
        $length = $request->headers->get('Content-Length');

        if (is_numeric($length)) {
            return (int) $length;
        }

        try {
            return strlen((string) $request->getContent());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    private function withFiles(array $data, array $files): array
    {
        foreach ($files as $key => $file) {
            $data[$key] = $file instanceof UploadedFile
                ? ['name' => $file->getClientOriginalName(), 'size' => (int) $file->getSize(), 'mime' => $file->getClientMimeType()]
                : (is_array($file) ? $this->withFiles([], $file) : null);
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function capDepth(array $data, int $depth, bool &$truncated): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($depth >= self::MAX_BODY_DEPTH) {
                $data[$key] = self::TRUNCATED;
                $truncated = true;
            } else {
                $data[$key] = $this->capDepth($value, $depth + 1, $truncated);
            }
        }

        return $data;
    }

    /**
     * Keep top-level fields, in order, while the serialised body fits.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function capSize(array $data, bool &$truncated): array
    {
        if ($this->encodedLength($data) <= self::MAX_BODY_BYTES) {
            return $data;
        }

        $truncated = true;
        $kept = [];

        foreach ($data as $key => $value) {
            $candidate = $kept + [$key => $value];

            if ($this->encodedLength($candidate) > self::MAX_BODY_BYTES) {
                $candidate = $kept + [$key => self::TRUNCATED];

                if ($this->encodedLength($candidate) > self::MAX_BODY_BYTES) {
                    break;
                }
            }

            $kept = $candidate;
        }

        return $kept;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function encodedLength(array $data): int
    {
        return strlen((string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
