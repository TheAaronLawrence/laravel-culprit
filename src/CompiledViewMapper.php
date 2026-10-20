<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Illuminate\Foundation\Exceptions\Renderer\Mappers\BladeMapper;
use Throwable;

/**
 * Maps a frame in a compiled Blade view (storage/framework/views/9f1c….php)
 * back to its `.blade.php` file and line.
 */
final class CompiledViewMapper
{
    /** @var array<string, string|null> */
    private array $sources = [];

    /**
     * @return array{file: string, line: int}|null
     */
    public function map(string $file, int $line): ?array
    {
        $source = $this->sourceOf($file);

        if ($source === null) {
            return null;
        }

        try {
            $mapped = app(BladeMapper::class)->detectLineNumber($source, $line);
        } catch (Throwable) {
            $mapped = $line;
        }

        return ['file' => $source, 'line' => $mapped];
    }

    private function sourceOf(string $file): ?string
    {
        if (array_key_exists($file, $this->sources)) {
            return $this->sources[$file];
        }

        return $this->sources[$file] = $this->readFooter($file);
    }

    /**
     * Laravel ends every compiled view with `/**PATH /abs/view.blade.php ENDPATH**\/`.
     */
    private function readFooter(string $file): ?string
    {
        $compiled = config('view.compiled');

        if (! is_string($compiled) || $compiled === '' || ! str_starts_with($file, rtrim($compiled, '/').'/') || ! is_file($file)) {
            return null;
        }

        $size = (int) @filesize($file);
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            fseek($handle, max(0, $size - 4096));
            $tail = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if (preg_match('/\/\*\*PATH (.+?) ENDPATH\*\*\//', $tail, $match) !== 1 || ! is_file($match[1])) {
            return null;
        }

        return $match[1];
    }
}
