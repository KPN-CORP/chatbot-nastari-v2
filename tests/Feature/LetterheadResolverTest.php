<?php

namespace Tests\Feature;

use App\Services\LetterheadResolver;
use Tests\TestCase;

/**
 * The guard that stops a letterhead from exhausting PHP's memory limit.
 *
 * Regression cover for two production fatals, both at fpdf.php:1366
 * (gzuncompress inside _parsepngstream's alpha-channel branch), triggered by
 * ~30.000 x 3.583 px RGBA letterhead PNGs that inflate to ~410 MB.
 *
 * The fixtures are hand-built PNG headers rather than real images: the resolver
 * only ever reads headers, so a valid IHDR is all it takes to reproduce the
 * dangerous case without allocating 400 MB in the test suite.
 */
class LetterheadResolverTest extends TestCase
{
    private string $sourceDir;

    private string $optimizedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nastari_kop_' . uniqid('', false);
        $this->sourceDir = $base . DIRECTORY_SEPARATOR . 'src';
        $this->optimizedDir = $base . DIRECTORY_SEPARATOR . 'opt';

        @mkdir($this->sourceDir, 0777, true);
        @mkdir($this->optimizedDir, 0777, true);

        config([
            'nastari.letterhead.source_path'         => $this->sourceDir,
            'nastari.letterhead.optimized_path'      => $this->optimizedDir,
            'nastari.letterhead.extensions'          => ['jpg', 'jpeg', 'png'],
            'nastari.letterhead.max_pixels'          => 40000000,
            'nastari.letterhead.max_projected_bytes' => 67108864,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->sourceDir, $this->optimizedDir] as $dir) {
            foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * Writes a PNG containing a real IHDR (so getimagesize reports the given
     * dimensions and colour type) and nothing else of substance.
     */
    private function writePng(string $dir, string $name, int $width, int $height, int $colorType): void
    {
        $ihdr = pack('N', $width) . pack('N', $height)
            . chr(8)            // bit depth
            . chr($colorType)   // 0 grey, 2 RGB, 3 indexed, 4 grey+alpha, 6 RGBA
            . chr(0) . chr(0) . chr(0);

        $chunk = function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $png = chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', gzcompress(str_repeat("\0", 32)))
            . $chunk('IEND', '');

        file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.png', $png);
    }

    private function writeJpeg(string $dir, string $name, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 255, 255, 255));
        imagejpeg($image, $dir . DIRECTORY_SEPARATOR . $name . '.jpg', 85);
        imagedestroy($image);
    }

    private function resolver(): LetterheadResolver
    {
        return new LetterheadResolver();
    }

    public function test_the_production_rgba_letterhead_is_refused(): void
    {
        // The real dimensions of PT Cempaka Sinergy Realty.png.
        $this->writePng($this->sourceDir, 'PT Cempaka Sinergy Realty', 29978, 3582, 6);

        $this->assertNull(
            $this->resolver()->resolve('PT Cempaka Sinergy Realty'),
            'A 107 MP RGBA PNG must never be handed to FPDF'
        );
    }

    public function test_a_large_png_without_alpha_is_still_refused_on_pixel_count(): void
    {
        // No alpha means no gzuncompress blow-up, but 107 MP is still over the
        // pixel ceiling and there is no reason to embed it.
        $this->writePng($this->sourceDir, 'PT Besar Tanpa Alpha', 29978, 3582, 2);

        $this->assertNull($this->resolver()->resolve('PT Besar Tanpa Alpha'));
    }

    public function test_a_reasonably_sized_rgba_png_is_accepted(): void
    {
        $this->writePng($this->sourceDir, 'PT Kecil RGBA', 1507, 163, 6);

        $result = $this->resolver()->resolve('PT Kecil RGBA');

        $this->assertNotNull($result);
        $this->assertSame(1507, $result['width']);
        $this->assertSame(163, $result['height']);
        $this->assertFalse($result['optimized']);
    }

    public function test_an_optimized_copy_is_preferred_over_the_oversized_source(): void
    {
        $this->writePng($this->sourceDir, 'PT Wahana Nusantara', 29978, 3590, 6);
        $this->writeJpeg($this->optimizedDir, 'PT Wahana Nusantara', 2480, 297);

        $result = $this->resolver()->resolve('PT Wahana Nusantara');

        $this->assertNotNull($result, 'Optimizing the letterhead must restore it, not just silence it');
        $this->assertTrue($result['optimized']);
        $this->assertSame(2480, $result['width']);
        $this->assertStringEndsWith('.jpg', $result['path']);
    }

    public function test_a_company_with_no_letterhead_resolves_to_null_rather_than_failing(): void
    {
        $this->assertNull($this->resolver()->resolve('PT Tidak Punya Kop'));
        $this->assertNull($this->resolver()->resolve(''));
        $this->assertNull($this->resolver()->resolve(null));
    }

    /**
     * The company name comes from the database and is concatenated into a
     * filesystem path.
     */
    public function test_directory_traversal_in_the_company_name_is_neutralised(): void
    {
        foreach (['../../../etc/passwd', '..\\..\\windows\\win.ini', '..', '.', '/etc/hosts'] as $name) {
            $this->assertNull(
                $this->resolver()->resolve($name),
                "Traversal attempt '{$name}' must not resolve to a file"
            );
        }
    }

    public function test_letterhead_is_scaled_into_the_header_box_preserving_ratio(): void
    {
        $fit = $this->resolver()->fitToBox(2480, 296);

        // Wider than the box ratio, so width is pinned to 210 mm.
        $this->assertEqualsWithDelta(210.0, $fit['width'], 0.01);
        $this->assertEqualsWithDelta(25.06, $fit['height'], 0.1);
        $this->assertEqualsWithDelta(0.0, $fit['x'], 0.01);

        // Taller than the box ratio, so height is pinned to 40 mm and it is
        // centred horizontally.
        $tall = $this->resolver()->fitToBox(400, 400);
        $this->assertEqualsWithDelta(40.0, $tall['height'], 0.01);
        $this->assertEqualsWithDelta(40.0, $tall['width'], 0.01);
        $this->assertEqualsWithDelta(85.0, $tall['x'], 0.01);
    }

    public function test_an_unreadable_file_is_skipped_not_fatal(): void
    {
        file_put_contents($this->sourceDir . DIRECTORY_SEPARATOR . 'PT Rusak.png', 'this is not a png');

        $this->assertNull($this->resolver()->resolve('PT Rusak'));
    }
}
