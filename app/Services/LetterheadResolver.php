<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Picks the letterhead image for a company and refuses to hand FPDF anything
 * it cannot afford to parse.
 *
 * Background: the letterhead is chosen by matching an employee's
 * `contribution_level` against a filename in storage/app/kop-surat-kpn. Four of
 * those files are ~30.000 x 3.583 px RGBA PNGs (only ~1 MB on disk, because
 * they are mostly white). FPDF's _parsepngstream() has to gzuncompress the
 * whole raster before it can split the alpha channel, which is a single ~410 MB
 * allocation, and that fatally exhausted the 512 MB limit inside the WhatsApp
 * webhook request - twice in production, both times at fpdf.php:1366.
 *
 * This resolver only ever reads image *headers*, never pixel data, so it is
 * cheap enough to run in the request path. Cost is projected from those headers
 * and compared against the budgets in config/nastari.php. Over budget means the
 * letter is generated without a letterhead rather than not generated at all.
 */
class LetterheadResolver
{
    /** Bytes per pixel of decompressed PNG scanline data, by IHDR colour type. */
    private const PNG_BYTES_PER_PIXEL = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    /**
     * @return array{path:string, width:int, height:int, optimized:bool}|null
     *         null when there is no usable letterhead for this company.
     */
    public function resolve(?string $companyName): ?array
    {
        $candidate = $this->locate($companyName);

        if ($candidate === null) {
            return null;
        }

        $size = @getimagesize($candidate['path']);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            Log::warning('LETTERHEAD_UNREADABLE', ['file' => basename($candidate['path'])]);

            return null;
        }

        [$width, $height] = $size;
        $pixels = $width * $height;
        $projected = $this->projectedCost($candidate['path'], $width, $height, $size[2]);

        $maxPixels = (int) config('nastari.letterhead.max_pixels', 40000000);
        $maxBytes = (int) config('nastari.letterhead.max_projected_bytes', 67108864);

        if ($pixels > $maxPixels || $projected > $maxBytes) {
            // Deliberately not an exception: a missing header is a cosmetic
            // problem, a fatal error is a user who never gets their letter.
            Log::warning('LETTERHEAD_SKIPPED_OVER_BUDGET', [
                'file'         => basename($candidate['path']),
                'dimensions'   => $width . 'x' . $height,
                'megapixels'   => round($pixels / 1000000, 1),
                'projected_mb' => round($projected / 1048576, 1),
                'budget_mb'    => round($maxBytes / 1048576, 1),
                'remedy'       => 'php artisan nastari:optimize-letterheads',
            ]);

            return null;
        }

        return [
            'path'      => $candidate['path'],
            'width'     => $width,
            'height'    => $height,
            'optimized' => $candidate['optimized'],
        ];
    }

    /**
     * Scale the image into the 210 x 40 mm header box, preserving aspect ratio.
     *
     * @return array{width:float, height:float, x:float}
     */
    public function fitToBox(
        int $imageWidth,
        int $imageHeight,
        float $boxWidth = 210.0,
        float $boxHeight = 40.0,
        float $pageWidth = 210.0
    ): array {
        $imageRatio = $imageWidth / max($imageHeight, 1);
        $boxRatio = $boxWidth / $boxHeight;

        if ($imageRatio > $boxRatio) {
            $width = $boxWidth;
            $height = $boxWidth / $imageRatio;
        } else {
            $height = $boxHeight;
            $width = $boxHeight * $imageRatio;
        }

        return [
            'width'  => $width,
            'height' => $height,
            'x'      => ($pageWidth - $width) / 2,
        ];
    }

    /**
     * Finds the file for a company, preferring an optimized copy.
     *
     * @return array{path:string, optimized:bool}|null
     */
    private function locate(?string $companyName): ?array
    {
        $name = $this->safeName($companyName);

        if ($name === null) {
            return null;
        }

        $extensions = (array) config('nastari.letterhead.extensions', ['jpg', 'jpeg', 'png']);

        $dirs = [
            [config('nastari.letterhead.optimized_path'), true],
            [config('nastari.letterhead.source_path'), false],
        ];

        foreach ($dirs as [$dir, $isOptimized]) {
            if (empty($dir) || ! is_dir($dir)) {
                continue;
            }

            foreach ($extensions as $ext) {
                $path = rtrim((string) $dir, '/\\') . DIRECTORY_SEPARATOR . $name . '.' . $ext;

                if (is_file($path)) {
                    return ['path' => $path, 'optimized' => $isOptimized];
                }
            }
        }

        return null;
    }

    /**
     * Company names come from the database and are concatenated into a path, so
     * strip anything that could escape the letterhead directory.
     */
    private function safeName(?string $companyName): ?string
    {
        $name = trim((string) $companyName);

        if ($name === '') {
            return null;
        }

        $name = str_replace(["\0", '/', '\\'], '', $name);
        $name = basename($name);
        $name = trim($name, '. ');

        return $name === '' ? null : $name;
    }

    /**
     * Peak bytes FPDF is expected to need for this image.
     *
     * JPEG is embedded verbatim, so it costs its file size. A PNG without an
     * alpha channel is passed through still deflated, so it also costs roughly
     * its file size. A PNG *with* alpha is the expensive case: FPDF inflates
     * every scanline, then builds separate colour and alpha strings from them.
     */
    private function projectedCost(string $path, int $width, int $height, int $type): int
    {
        $fileSize = (int) (@filesize($path) ?: 0);

        if ($type === IMAGETYPE_JPEG) {
            return $fileSize;
        }

        if ($type === IMAGETYPE_GIF) {
            // FPDF converts GIF through GD, which decodes to a full bitmap.
            return $width * $height * 4;
        }

        if ($type !== IMAGETYPE_PNG) {
            return $fileSize;
        }

        $colorType = $this->pngColorType($path);

        if ($colorType === null || $colorType < 4) {
            return $fileSize;
        }

        $bytesPerPixel = self::PNG_BYTES_PER_PIXEL[$colorType] ?? 4;

        // Inflated scanlines (1 filter byte per row), plus the colour and alpha
        // copies built from them in the same scope.
        $raw = $height * (1 + $bytesPerPixel * $width);
        $split = $height * $bytesPerPixel * $width;

        return (int) ($raw + $split);
    }

    /** Reads the IHDR colour type without decoding any pixels. */
    private function pngColorType(string $path): ?int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fread($handle, 26);
        fclose($handle);

        if ($header === false || strlen($header) < 26) {
            return null;
        }

        // Byte 25 of a PNG is the IHDR colour type: 0 grey, 2 RGB, 3 indexed,
        // 4 grey+alpha, 6 RGBA.
        return ord($header[25]);
    }
}
