<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Throwable;

/**
 * Builds the frame list for one exception.
 *
 * Frame 0 is where the exception was thrown (its file and line, in the function
 * that threw). Each later frame is a call site, in the function that made the
 * call, so `class`/`function` always name the code at `file:line`. The last
 * frame is `{main}`. Arguments are never sent.
 */
final class TraceBuilder
{
    /** Traces longer than this keep only the head and tail. */
    private const MAX_FRAMES = 100;

    private const KEEP_HEAD = 50;

    private const KEEP_TAIL = 20;

    /** The thrown frame plus this many app frames get a source snippet. */
    private const FRAMES_WITH_SOURCE = 10;

    public function __construct(
        private readonly PathNormaliser $paths,
        private readonly SourceReader $source,
        private readonly CompiledViewMapper $views,
    ) {}

    /**
     * @return array{trace: list<array<string, mixed>>, trace_omitted: int}
     */
    public function build(Throwable $exception): array
    {
        $frames = $this->collapseRepeats($this->frames($exception));

        $omitted = 0;
        if (count($frames) > self::MAX_FRAMES) {
            $omitted = count($frames) - self::KEEP_HEAD - self::KEEP_TAIL;
            $frames = [...array_slice($frames, 0, self::KEEP_HEAD), ...array_slice($frames, -self::KEEP_TAIL)];
        }

        try {
            return ['trace' => $this->attachSource($frames), 'trace_omitted' => $omitted];
        } finally {
            $this->source->flush();
        }
    }

    /**
     * The thrown location, mapped from a compiled view when needed.
     *
     * @return array{file: string, line: int}
     */
    public function location(Throwable $exception): array
    {
        return $this->locate($exception->getFile(), $exception->getLine());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frames(Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $frames = [];

        $file = $exception->getFile();
        $line = $exception->getLine();

        foreach ([...$trace, null] as $call) {
            $frames[] = $this->frame(
                $file,
                $line,
                $call === null ? null : ($call['class'] ?? null),
                $call === null ? '{main}' : $call['function'],
            );

            $file = $call['file'] ?? null;
            $line = $call['line'] ?? null;
        }

        return $frames;
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(?string $file, ?int $line, ?string $class, string $function): array
    {
        $absolute = null;

        if ($file !== null && $file !== '') {
            ['file' => $absolute, 'line' => $line] = $this->locate($file, (int) $line);
        }

        $relative = $absolute === null ? null : $this->paths->relative($absolute);
        $function = $this->paths->inText($function);

        return [
            'file' => $relative,
            'line' => $line,
            'class' => $class === null ? null : $this->paths->className($class),
            'function' => $function,
            'is_vendor' => $this->paths->isVendor($relative, $function),
            'absolute_file' => $absolute,
        ];
    }

    /**
     * @return array{file: string, line: int}
     */
    private function locate(string $file, int $line): array
    {
        return $this->views->map($file, $line) ?? ['file' => $file, 'line' => $line];
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    private function collapseRepeats(array $frames): array
    {
        $collapsed = [];
        $previousKey = null;

        foreach ($frames as $frame) {
            $key = [$frame['file'], $frame['line'], $frame['class'], $frame['function']];

            if ($key === $previousKey) {
                $last = array_key_last($collapsed);
                $collapsed[$last]['repeat'] = ($collapsed[$last]['repeat'] ?? 1) + 1;

                continue;
            }

            $collapsed[] = $frame;
            $previousKey = $key;
        }

        return $collapsed;
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    private function attachSource(array $frames): array
    {
        $vendorSource = (bool) config('culprit.vendor_source', false);
        $budget = self::FRAMES_WITH_SOURCE;

        foreach ($frames as $index => $frame) {
            $eligible = $index === 0 || ($budget > 0 && ($vendorSource || ! $frame['is_vendor']));
            $source = null;

            if ($eligible && is_string($frame['absolute_file']) && is_int($frame['line'])) {
                $source = $this->source->read($frame['absolute_file'], $frame['line']);
            }

            if ($eligible && ($vendorSource || ! $frame['is_vendor'])) {
                $budget--;
            }

            unset($frame['absolute_file']);
            $frames[$index] = $this->ordered($frame, $source);
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $frame
     * @param  array{start: int, lines: list<string>}|null  $source
     * @return array<string, mixed>
     */
    private function ordered(array $frame, ?array $source): array
    {
        $ordered = [
            'file' => $frame['file'],
            'line' => $frame['line'],
            'class' => $frame['class'],
            'function' => $frame['function'],
            'is_vendor' => $frame['is_vendor'],
            'source' => $source,
        ];

        if (isset($frame['repeat'])) {
            $ordered['repeat'] = $frame['repeat'];
        }

        return $ordered;
    }
}
