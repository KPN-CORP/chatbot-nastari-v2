<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * One-off (repeatable) normalisation of the letterhead images.
 *
 * Writes a compact JPEG copy of every oversized letterhead into
 * config('nastari.letterhead.optimized_path'), which LetterheadResolver
 * prefers over the original. Source files are never modified or deleted.
 *
 * This is the only place in the codebase that decodes a full letterhead
 * bitmap. GD cannot resize without decoding, and a 107 MP truecolor image is
 * ~430 MB, so the command raises its *own* memory limit while it runs. Nothing
 * in the request path does this: LetterheadResolver reads headers only.
 */
class OptimizeLetterheads extends Command
{
    protected $signature = 'nastari:optimize-letterheads
                            {--force : Re-encode even when an up-to-date optimized copy exists}
                            {--dry-run : Report what would change without writing anything}';

    protected $description = 'Downscale oversized kop surat images so FPDF cannot exhaust memory on them';

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('The GD extension is required to resize images.');

            return self::FAILURE;
        }

        $sourceDir = (string) config('nastari.letterhead.source_path');
        $targetDir = (string) config('nastari.letterhead.optimized_path');

        if (! is_dir($sourceDir)) {
            $this->error("Letterhead directory not found: {$sourceDir}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! is_dir($targetDir) && ! @mkdir($targetDir, 0775, true)) {
            $this->error("Could not create {$targetDir}");

            return self::FAILURE;
        }

        $maxWidth = (int) config('nastari.letterhead.max_width', 2480);
        $quality = (int) config('nastari.letterhead.optimize_quality', 85);
        $maxPixels = (int) config('nastari.letterhead.max_pixels', 40000000);
        $maxBytes = (int) config('nastari.letterhead.max_projected_bytes', 67108864);

        $originalLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string) config('nastari.letterhead.optimize_memory_limit', '1024M'));

        $rows = [];
        $converted = 0;
        $skipped = 0;
        $failed = 0;

        try {
            foreach ($this->sourceFiles($sourceDir) as $path) {
                $name = pathinfo($path, PATHINFO_FILENAME);
                $size = @getimagesize($path);

                if ($size === false) {
                    $rows[] = [$name, '-', 'unreadable', '-'];
                    $failed++;
                    continue;
                }

                [$width, $height] = $size;
                $needsWork = ($width > $maxWidth)
                    || ($width * $height > $maxPixels)
                    || ($this->rawCost($path, $width, $height, $size[2]) > $maxBytes);

                if (! $needsWork) {
                    $skipped++;
                    continue;
                }

                $target = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $name . '.jpg';

                if (! $this->option('force') && is_file($target) && filemtime($target) >= filemtime($path)) {
                    $rows[] = [$name, $width . 'x' . $height, 'already optimized', $this->kb($target)];
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $rows[] = [$name, $width . 'x' . $height, 'would convert', '-'];
                    $converted++;
                    continue;
                }

                $result = $this->convert($path, $target, $size[2], $width, $height, $maxWidth, $quality);

                if ($result === null) {
                    $rows[] = [$name, $width . 'x' . $height, 'FAILED', '-'];
                    $failed++;
                    continue;
                }

                $rows[] = [$name, $width . 'x' . $height, '-> ' . $result, $this->kb($target)];
                $converted++;
            }
        } finally {
            ini_set('memory_limit', (string) $originalLimit);
        }

        if ($rows !== []) {
            $this->table(['Letterhead', 'Source size', 'Action', 'Result'], $rows);
        }

        $this->info(sprintf(
            '%s: %d converted, %d already fine, %d failed.',
            $dryRun ? 'Dry run' : 'Done',
            $converted,
            $skipped,
            $failed
        ));

        if ($failed > 0) {
            $this->warn('Failed files keep their original. Letters for those companies are issued without a letterhead until the source image is re-exported smaller.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, string> */
    private function sourceFiles(string $dir): array
    {
        $files = [];

        foreach ((array) config('nastari.letterhead.extensions', ['jpg', 'jpeg', 'png']) as $ext) {
            foreach ((array) glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.' . $ext) as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Decode, flatten any transparency onto white, downscale, save as JPEG.
     *
     * Flattening is what removes the danger: the output has no alpha channel,
     * so FPDF never enters _parsepngstream()'s alpha branch again.
     */
    private function convert(string $source, string $target, int $type, int $width, int $height, int $maxWidth, int $quality): ?string
    {
        $needed = $width * $height * 4;
        $available = $this->memoryAvailable();

        if ($available > 0 && $needed > $available) {
            $this->warn(sprintf(
                'Skipping %s: decoding needs ~%d MB but only ~%d MB is available. Re-export this file at %d px wide or raise nastari.letterhead.optimize_memory_limit.',
                basename($source),
                (int) ($needed / 1048576),
                (int) ($available / 1048576),
                $maxWidth
            ));

            return null;
        }

        $image = match ($type) {
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_GIF  => @imagecreatefromgif($source),
            default        => false,
        };

        if ($image === false) {
            return null;
        }

        try {
            $targetWidth = min($width, $maxWidth);
            $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($canvas === false) {
                return null;
            }

            // JPEG has no alpha, so transparent areas must land on white rather
            // than on the default black canvas.
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);

            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            // Free the large source bitmap before encoding the small one.
            imagedestroy($image);
            $image = null;

            if (! imagejpeg($canvas, $target, $quality)) {
                imagedestroy($canvas);

                return null;
            }

            imagedestroy($canvas);

            return $targetWidth . 'x' . $targetHeight . ' jpg';
        } finally {
            if ($image !== null && $image !== false) {
                imagedestroy($image);
            }
        }
    }

    /** Same projection as LetterheadResolver, kept local to avoid coupling. */
    private function rawCost(string $path, int $width, int $height, int $type): int
    {
        if ($type !== IMAGETYPE_PNG) {
            return (int) (@filesize($path) ?: 0);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        $header = fread($handle, 26);
        fclose($handle);

        if ($header === false || strlen($header) < 26) {
            return 0;
        }

        $colorType = ord($header[25]);

        if ($colorType < 4) {
            return (int) (@filesize($path) ?: 0);
        }

        $bpp = $colorType === 4 ? 2 : 4;

        return $height * (1 + $bpp * $width) + $height * $bpp * $width;
    }

    private function memoryAvailable(): int
    {
        $limit = $this->bytes((string) ini_get('memory_limit'));

        if ($limit <= 0) {
            return 0; // unlimited
        }

        return max(0, $limit - memory_get_usage(true) - 33554432); // keep 32 MB headroom
    }

    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function kb(string $path): string
    {
        $size = @filesize($path);

        return $size === false ? '-' : round($size / 1024) . ' KB';
    }
}
