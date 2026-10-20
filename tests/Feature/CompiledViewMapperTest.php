<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\View\Compilers\BladeCompiler;
use TheAaronLawrence\Culprit\CompiledViewMapper;
use TheAaronLawrence\Culprit\Tests\TestCase;

final class CompiledViewMapperTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/culprit-views-'.uniqid();
        File::ensureDirectoryExists($this->directory.'/compiled');
        config(['view.compiled' => $this->directory.'/compiled']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_maps_a_compiled_line_back_to_the_blade_line(): void
    {
        $source = $this->directory.'/page.blade.php';
        file_put_contents($source, "<div>\n{{-- a\ncomment\nover\nlines --}}\n@if (true)\n<p>{{ \$marker }}</p>\n@endif\n</div>\n");

        $compiler = app('blade.compiler');
        $this->assertInstanceOf(BladeCompiler::class, $compiler);
        $compiler->setPath($source);
        $compiled = $compiler->getCompiledPath($source);
        $compiler->compile($source);

        $compiledLine = $this->lineOf($compiled, '$marker');

        $mapped = app(CompiledViewMapper::class)->map($compiled, $compiledLine);

        $this->assertSame($source, $mapped['file'] ?? null);
        $this->assertSame(7, $mapped['line'] ?? null);
    }

    private function lineOf(string $file, string $marker): int
    {
        foreach (file($file) ?: [] as $index => $line) {
            if (str_contains($line, $marker)) {
                return $index + 1;
            }
        }

        $this->fail("Marker {$marker} not found in {$file}.");
    }
}
