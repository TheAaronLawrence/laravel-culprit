<?php

declare(strict_types=1);

namespace TheAaronLawrence\Culprit\Tests\Feature;

use Illuminate\Support\ItemNotFoundException;
use TheAaronLawrence\Culprit\SourceReader;
use TheAaronLawrence\Culprit\Tests\Fixtures\Thrower;

final class SourceTest extends ReporterTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_thrown_frame_has_10_lines_before_and_12_after(): void
    {
        $payload = $this->payloadFor(Thrower::make());
        $source = $payload['trace'][0]['source'];

        $this->assertSame($payload['line'] - 10, $source['start']);
        $this->assertCount(23, $source['lines']);
        $this->assertStringContainsString('MARKER:THROW', $source['lines'][10]);
    }

    public function test_the_window_is_cut_at_the_start_of_the_file(): void
    {
        $source = app(SourceReader::class)->read($this->tempFile("<?php\nline 2\nline 3\nline 4\n"), 2);

        $this->assertSame(1, $source['start']);
        $this->assertSame(['<?php', 'line 2', 'line 3', 'line 4'], $source['lines']);
    }

    public function test_only_the_thrown_frame_and_first_10_app_frames_have_source(): void
    {
        $trace = $this->payloadFor(Thrower::pingPong(30))['trace'];

        $withSource = array_filter($trace, fn (array $frame): bool => $frame['source'] !== null);

        $this->assertCount(10, $withSource);
        foreach ($withSource as $frame) {
            $this->assertFalse($frame['is_vendor']);
        }
    }

    public function test_the_thrown_frame_has_source_even_in_vendor_code(): void
    {
        try {
            collect()->firstOrFail();
            $this->fail('firstOrFail() should have thrown.');
        } catch (ItemNotFoundException $exception) {
            $trace = $this->payloadFor($exception)['trace'];
        }

        $this->assertTrue($trace[0]['is_vendor']);
        $this->assertNotNull($trace[0]['source']);
        $this->assertFalse($trace[1]['is_vendor']);
        $this->assertNotNull($trace[1]['source']);
    }

    public function test_vendor_frames_have_no_source_by_default(): void
    {
        foreach ($this->payloadFor(Thrower::make())['trace'] as $frame) {
            if ($frame['is_vendor']) {
                $this->assertNull($frame['source']);
            }
        }
    }

    public function test_vendor_frames_can_include_source(): void
    {
        config(['culprit.vendor_source' => true]);

        $vendorWithSource = array_filter(
            $this->payloadFor(Thrower::make())['trace'],
            fn (array $frame): bool => $frame['is_vendor'] && $frame['source'] !== null,
        );

        $this->assertNotEmpty($vendorWithSource);
    }

    public function test_files_that_must_not_be_read_have_no_source(): void
    {
        $reader = app(SourceReader::class);

        $this->assertNull($reader->read(base_path('config/app.php'), 5));
        $this->assertNull($reader->read(base_path('.env.example'), 1));
        $this->assertNull($reader->read('phar:///tmp/tool.phar/src/A.php', 1));
        $this->assertNull($reader->read(base_path('routes/web.php')."(12) : eval()'d code", 1));
        $this->assertNull($reader->read(base_path('missing/File.php'), 1));
    }

    public function test_files_over_2_mb_have_no_source(): void
    {
        $file = $this->tempFile(str_repeat(str_repeat('x', 99)."\n", 21_000));

        $this->assertNull(app(SourceReader::class)->read($file, 10));
    }

    public function test_long_lines_are_cut_at_400_characters(): void
    {
        $file = $this->tempFile("<?php\n".str_repeat('ü', 1_000)."\n");

        $lines = app(SourceReader::class)->read($file, 2)['lines'];

        $this->assertSame(400, mb_strlen($lines[1]));
    }

    public function test_invalid_utf8_in_source_is_scrubbed(): void
    {
        $file = $this->tempFile("<?php\n\$name = 'caf\xE9';\n");

        $lines = app(SourceReader::class)->read($file, 2)['lines'];

        $this->assertTrue(mb_check_encoding($lines[1], 'UTF-8'));
        $this->assertStringStartsWith('$name = \'caf', $lines[1]);
    }

    private function tempFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'et-source-').'.php';
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;

        return $file;
    }
}
