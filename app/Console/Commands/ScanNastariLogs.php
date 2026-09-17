<?php

namespace App\Console\Commands;

use App\Services\NastariLogScanner;
use Illuminate\Console\Command;

class ScanNastariLogs extends Command
{
    protected $signature = 'nastari:scan-logs
                            {--fresh : Bangun ulang dari awal (data turunan; aman)}';

    protected $description = 'Memindai storage/logs untuk entri ERROR ke atas ke tabel nastari_log_entries';

    public function handle(NastariLogScanner $scanner): int
    {
        $started = microtime(true);

        try {
            $result = $scanner->scan((bool) $this->option('fresh'));
        } catch (\Throwable $e) {
            $this->error('Pemindaian gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['File', 'Dibaca', 'Byte baru', 'Entri', 'Tersimpan', 'Sudah ada', 'Dipangkas', 'Rotasi', 'Detik'],
            [[
                $result['files'],
                $result['scanned'],
                number_format($result['bytes']),
                number_format($result['entries']),
                number_format($result['inserted']),
                number_format($result['skipped']),
                number_format($result['pruned']),
                $result['rotated'],
                round(microtime(true) - $started, 2),
            ]]
        );

        if ($result['rotated'] > 0) {
            $this->line('  ' . $result['rotated'] . ' file terdeteksi dirotasi atau dipotong; pembacaannya dimulai ulang dari awal.');
        }

        $this->info('Selesai. Hanya byte baru yang dibaca tiap kali jalan, jadi aman dijadwalkan sering.');

        return self::SUCCESS;
    }
}
