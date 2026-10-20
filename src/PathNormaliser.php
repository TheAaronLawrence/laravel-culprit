<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit;

/**
 * Turns absolute paths under the app's base path into relative ones, and
 * decides which frames belong to the framework rather than your code.
 */
final class PathNormaliser
{
    private const FRAMEWORK_FILES = ['public/index.php', 'artisan'];

    private readonly string $prefix;

    public function __construct(string $basePath)
    {
        $this->prefix = rtrim($basePath, '/\\').DIRECTORY_SEPARATOR;
    }

    /**
     * Strip the base path from the start of the path. Paths outside it
     * (`/usr/share/php/...`, `phar://...`) are left as they are.
     */
    public function relative(string $path): string
    {
        return str_starts_with($path, $this->prefix) ? substr($path, strlen($this->prefix)) : $path;
    }

    /**
     * Strip the base path wherever it appears in free text (messages, closure names).
     */
    public function inText(string $text): string
    {
        return str_replace($this->prefix, '', $text);
    }

    public function isVendor(?string $relativeFile, string $function): bool
    {
        if ($relativeFile === null || $function === '{main}') {
            return true;
        }

        return str_starts_with($relativeFile, 'vendor/')
            || in_array($relativeFile, self::FRAMEWORK_FILES, true)
            || $this->isAbsolute($relativeFile);
    }

    /**
     * `class@anonymous\0/abs/path/File.php:12$0` becomes `class@anonymous path/File.php`.
     */
    public function className(string $class): string
    {
        $nul = strpos($class, "\0");

        if ($nul === false) {
            return $class;
        }

        $location = (string) preg_replace('/:\d+\$[0-9a-f]+$/i', '', substr($class, $nul + 1));

        return substr($class, 0, $nul).' '.$this->relative($location);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_contains($path, '://')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
