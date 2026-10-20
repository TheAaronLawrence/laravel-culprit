<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use SplFileObject;
use Throwable;

/**
 * Reads the lines around a frame's line, without loading the whole file.
 */
final class SourceReader
{
    public const LINES_BEFORE = 10;

    public const LINES_AFTER = 12;

    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    private const MAX_LINE_CHARACTERS = 400;

    /** @var array<string, SplFileObject|false> */
    private array $files = [];

    public function __construct(private readonly PathNormaliser $paths) {}

    /**
     * Close cached files, so a long-running worker doesn't hold them open.
     */
    public function flush(): void
    {
        $this->files = [];
    }

    /**
     * @return array{start: int, lines: list<string>}|null
     */
    public function read(string $path, int $line): ?array
    {
        if ($line < 1) {
            return null;
        }

        $file = $this->open($path);

        if ($file === false) {
            return null;
        }

        try {
            $start = max(1, $line - self::LINES_BEFORE);
            $lines = [];

            $file->seek($start - 1);

            while (! $file->eof() && count($lines) < $line - $start + 1 + self::LINES_AFTER) {
                $current = $file->current();
                $lines[] = $this->clean(is_string($current) ? $current : '');
                $file->next();
            }

            // The last line of a file ending in a newline reads as one empty line.
            if ($lines !== [] && end($lines) === '' && $file->eof()) {
                array_pop($lines);
            }

            return $lines === [] ? null : ['start' => $start, 'lines' => $lines];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cached per file, because the same file shows up in several frames.
     */
    private function open(string $path): SplFileObject|false
    {
        if (array_key_exists($path, $this->files)) {
            return $this->files[$path];
        }

        return $this->files[$path] = $this->isReadable($path) ? $this->tryOpen($path) : false;
    }

    private function tryOpen(string $path): SplFileObject|false
    {
        try {
            return new SplFileObject($path, 'r');
        } catch (Throwable) {
            return false;
        }
    }

    private function isReadable(string $path): bool
    {
        if (str_contains($path, '://') || str_contains($path, "eval()'d code")) {
            return false;
        }

        $relative = $this->paths->relative($path);

        if (str_starts_with($relative, 'config/') || str_starts_with(basename($path), '.env')) {
            return false;
        }

        return is_file($path) && is_readable($path) && (int) @filesize($path) <= self::MAX_FILE_BYTES;
    }

    private function clean(string $line): string
    {
        $line = mb_scrub(rtrim($line, "\r\n"), 'UTF-8');

        return mb_strlen($line) > self::MAX_LINE_CHARACTERS ? mb_substr($line, 0, self::MAX_LINE_CHARACTERS) : $line;
    }
}
