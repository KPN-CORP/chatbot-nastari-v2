<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes generated letter PDFs older than the retention window.
 *
 * Every generated letter was kept forever in storage/app/surat-keterangan.
 * The letter_logs row (including its file_path and nomor_surat) is never
 * touched, so the audit trail survives; only the PDF binary is removed once
 * the employee has had ample time to download it from WhatsApp.
 */
class PruneGeneratedLetters extends Command
{
    protected $signature = 'nastari:prune-letters
                            {--days= : Override the retention window in days}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete generated letter PDFs older than the retention window';

    public function handle(): int
    {
        $dir = (string) config('nastari.letters.output_path');
        $days = (int) ($this->option('days') ?: config('nastari.letters.retention_days', 30));

        if ($days < 1) {
            $this->error('Retention window must be at least 1 day.');

            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            $this->info("Nothing to do: {$dir} does not exist.");

            return self::SUCCESS;
        }

        $cutoff = time() - ($days * 86400);
        $dryRun = (bool) $this->option('dry-run');

        $deleted = 0;
        $keptCount = 0;
        $freedBytes = 0;
        $failed = 0;

        foreach ((array) glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.pdf') as $file) {
            $mtime = @filemtime($file);

            if ($mtime === false || $mtime >= $cutoff) {
                $keptCount++;
                continue;
            }

            $size = (int) (@filesize($file) ?: 0);

            if ($dryRun) {
                $deleted++;
                $freedBytes += $size;
                continue;
            }

            if (@unlink($file)) {
                $deleted++;
                $freedBytes += $size;
            } else {
                $failed++;
            }
        }

        $this->info(sprintf(
            '%s: %d file(s) older than %d days (%s), %d kept, %d could not be deleted.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $days,
            $this->humanBytes($freedBytes),
            $keptCount,
            $failed
        ));

        if ($failed > 0) {
            Log::warning('LETTER_PRUNE_PARTIAL', ['failed' => $failed, 'dir' => $dir]);
        }

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}
