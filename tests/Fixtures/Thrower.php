<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Fixtures;

use RuntimeException;
use Throwable;

/**
 * Throws from known lines, so the reporter tests can check frames and snippets.
 * Lines are found by their marker comments, so this file can be edited freely.
 */
final class Thrower
{
    public static function make(string $message = 'Thrown on purpose'): RuntimeException
    {
        try {
            self::throw($message);
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    public static function throw(string $message): never
    {
        throw new RuntimeException($message); // MARKER:THROW
    }

    /**
     * The same frame many times in a row (simple recursion).
     */
    public static function recursion(int $depth): RuntimeException
    {
        try {
            self::recurse($depth);
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    private static function recurse(int $depth): never
    {
        if ($depth === 0) {
            throw new RuntimeException('Bottom of the recursion'); // MARKER:RECURSE_THROW
        }

        self::recurse($depth - 1); // MARKER:RECURSE_CALL
    }

    /**
     * Many distinct app frames (two functions calling each other).
     */
    public static function pingPong(int $depth): RuntimeException
    {
        try {
            self::ping($depth);
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    private static function ping(int $depth): never
    {
        if ($depth === 0) {
            throw new RuntimeException('Bottom of the ping-pong');
        }

        self::pong($depth - 1);
    }

    private static function pong(int $depth): never
    {
        self::ping($depth);
    }

    /**
     * An exception with the given number of previous exceptions behind it.
     */
    public static function chain(int $previous): Throwable
    {
        $exception = new RuntimeException('Root cause', 1);

        for ($i = 1; $i <= $previous; $i++) {
            $exception = new RuntimeException("Wrapper {$i}", $i + 1, $exception);
        }

        return $exception;
    }

    public static function anonymous(): RuntimeException
    {
        return new class('Anonymous failure') extends RuntimeException {}; // MARKER:ANONYMOUS
    }

    // Padding below, so the snippet window after MARKER:THROW is never cut short by
    // the end of the file.
    //
    //
    //
    //
    //
    //
    //
    //
    //
    //
    //
    //
}
