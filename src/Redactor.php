<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

/**
 * Replaces sensitive values with "[redacted]" before anything leaves the app.
 *
 * Keys match the configured list case-insensitively, as substrings, at any
 * depth. The key is kept so you can see the field was there.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redact(array $values): array
    {
        $keys = $this->keys();
        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->matches($key, $keys)) {
                $redacted[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Redact query-string values, keeping everything else exactly as it was.
     */
    public function url(string $url): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $question = strpos($url, '?');
        if ($question === false) {
            return $url.$fragment;
        }

        $keys = $this->queryKeys();
        $pairs = explode('&', substr($url, $question + 1));

        foreach ($pairs as $index => $pair) {
            [$key] = explode('=', $pair, 2);

            if ($this->matches(urldecode($key), $keys)) {
                $pairs[$index] = $key.'='.self::REDACTED;
            }
        }

        return substr($url, 0, $question + 1).implode('&', $pairs).$fragment;
    }

    /**
     * Redact sensitive option values in a command line: `--password=x`, `--password x`.
     */
    public function commandLine(string $commandLine): string
    {
        $keys = $this->keys();
        $value = '(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^\s\'"]+)';

        return (string) preg_replace_callback(
            '/(?<=^|\s)(--?)([A-Za-z0-9_.\-]+)(=|\s+)(?!-)('.$value.')/',
            fn (array $match): string => $this->matches($match[2], $keys)
                ? $match[1].$match[2].$match[3].self::REDACTED
                : $match[0],
            $commandLine,
        );
    }

    /**
     * @param  list<string>|null  $keys
     */
    public function isSensitive(string $key, ?array $keys = null): bool
    {
        return $this->matches($key, $keys ?? $this->keys());
    }

    /**
     * @param  list<string>  $keys
     */
    private function matches(string $key, array $keys): bool
    {
        $key = strtolower($key);

        foreach ($keys as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        return $this->normalise(config('culprit.redact', []));
    }

    /**
     * @return list<string>
     */
    private function queryKeys(): array
    {
        return [...$this->keys(), ...$this->normalise(config('culprit.redact_query', []))];
    }

    /**
     * @return list<string>
     */
    private function normalise(mixed $keys): array
    {
        if (! is_array($keys)) {
            throw new \InvalidArgumentException('culprit redaction keys must be a list of strings.');
        }

        return array_values(array_map(fn (mixed $key): string => strtolower((string) $key), $keys));
    }
}
