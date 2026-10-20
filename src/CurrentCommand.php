<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

use Symfony\Component\Console\Input\InputInterface;

/**
 * The Artisan command being run, if any.
 *
 * Set on `CommandStarting`. Removed on `CommandFinished` only when the command
 * succeeded: a failing command fires `CommandFinished` before its exception
 * is reported, and the report still needs to know which command failed.
 */
final class CurrentCommand
{
    /** @var list<array{name: string, input: string}> */
    private array $stack = [];

    public function start(string $name, InputInterface $input): void
    {
        $this->stack[] = ['name' => $name, 'input' => (string) $input];
    }

    public function finish(int $exitCode): void
    {
        if ($exitCode === 0) {
            array_pop($this->stack);
        }
    }

    public function exists(): bool
    {
        return $this->stack !== [];
    }

    /**
     * @return array{name: string, input: string}|null
     */
    public function current(): ?array
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }
}
