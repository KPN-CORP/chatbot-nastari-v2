<?php

namespace App\Console\Commands;

use App\Services\NastariEventIngestor;
use Illuminate\Console\Command;

class IngestNastariLogs extends Command
{
    protected $signature = 'nastari:ingest-logs
                            {--since= : Only ingest lines on/after this date (YYYY-MM-DD)}
                            {--fresh : Rebuild the table from scratch (derived data; safe)}';

    protected $description = 'Parse storage/logs into the nastari_events analytics table (idempotent)';

    public function handle(NastariEventIngestor $ingestor): int
    {
        $since = $this->option('since');

        $this->info('Ingesting Nastari log events' . ($since ? " since {$since}" : ' (all available files)') . '…');

        $started = microtime(true);

        try {
            $result = $ingestor->ingest($since, (bool) $this->option('fresh'));
        } catch (\Throwable $e) {
            $this->error('Ingest failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $seconds = round(microtime(true) - $started, 2);

        $this->table(
            ['Files', 'Lines read', 'Inserted', 'Already present', 'After cutover', 'Superseded removed', 'Pandu Q&A', 'Seconds'],
            [[
                $result['files'],
                number_format($result['lines']),
                number_format($result['inserted']),
                number_format($result['skipped']),
                number_format($result['after_cutover'] ?? 0),
                number_format($result['superseded_removed'] ?? 0),
                number_format($result['pandu_qa'] ?? 0),
                $seconds,
            ]]
        );

        if (($result['pandu_qa'] ?? 0) > 0) {
            $this->line('  Recovered ' . number_format($result['pandu_qa']) . ' Pandu question/answer pair(s) into pandu_qa — the answer text exists nowhere else.');
        }

        if (($result['pandu_qa_linked'] ?? 0) > 0) {
            $this->line('  Linked ' . number_format($result['pandu_qa_linked']) . ' escalation ticket(s) to the question that triggered them (exact phone + question match only).');
        }

        if (($result['after_cutover'] ?? 0) > 0) {
            $this->line('  "After cutover" lines were skipped because the application already records those days itself.');
        }

        if (($result['superseded_removed'] ?? 0) > 0) {
            $this->warn('  Removed ' . number_format($result['superseded_removed']) . ' log-derived row(s) that overlapped live events, to stop that day being counted twice.');
        }

        $this->info('Done. Logs rotate after 14 days; nastari_events keeps the history.');

        return self::SUCCESS;
    }
}
