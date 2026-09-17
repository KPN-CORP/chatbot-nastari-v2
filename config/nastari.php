<?php

/*
|--------------------------------------------------------------------------
| Nastari application settings
|--------------------------------------------------------------------------
|
| Everything here is read through config() rather than env() so that
| `php artisan config:cache` stays safe. Several older services in this app
| call env() at runtime and break under a cached config; do not copy that
| pattern into new code.
|
*/

return [

    /*
    | Letterhead (kop surat) handling.
    |
    | FPDF has to inflate an RGBA PNG in full before it can split the alpha
    | channel out. Four of the source letterheads are ~30.000 x 3.583 px RGBA,
    | which needs ~410 MB in a single allocation and killed the request with
    | "Allowed memory size exhausted" at fpdf.php:1366. These budgets stop that
    | from ever reaching FPDF again.
    */
    'letterhead' => [
        'source_path'    => storage_path('app/kop-surat-kpn'),
        'optimized_path' => storage_path('app/kop-surat-kpn-optimized'),

        'extensions' => ['jpg', 'jpeg', 'png'],

        // The letterhead is drawn into a 210 x 40 mm box. At 300 dpi that is
        // ~2.480 px wide; anything beyond that is resolution the PDF discards.
        'max_width' => 2480,

        // Hard ceilings applied before FPDF sees the file. A letterhead over
        // either limit is skipped: the letter is still produced, just without
        // its header, and the skip is logged.
        'max_pixels'          => 40_000_000,   // 40 MP
        'max_projected_bytes' => 67_108_864,   // 64 MB of projected FPDF cost

        // JPEG quality used when re-encoding an oversized letterhead.
        'optimize_quality' => 85,

        // Memory ceiling for the one-off `nastari:optimize-letterheads` CLI
        // command only. GD has to decode the whole image to resize it, and a
        // 107 MP truecolor bitmap is ~430 MB. This never applies to a web
        // request: the request path only ever reads image headers.
        'optimize_memory_limit' => '1024M',
    ],

    /*
    | Scheduled task HTTP fallback.
    |
    | Whether cron runs `php artisan schedule:run` in production is
    | unconfirmed, so the daily sync is also reachable over HTTP for an
    | external pinger or the hosting panel's own cron. Leave the token empty to
    | disable the route entirely; the artisan command is unaffected either way.
    */
    'tasks' => [
        'token' => env('TASK_RUNNER_TOKEN'),
    ],

    /*
    | Structured activity logging (NastariActivityLogger -> nastari_events).
    */
    'logging' => [
        'enabled' => env('NASTARI_ACTIVITY_LOG', true),

        // Outbound HTTP defaults. Every Http:: call in this app was previously
        // left on Laravel's 30s default with no retry, so one hung external
        // service could hold a WhatsApp webhook request open for 30 seconds per
        // call, serially. These are applied globally in AppServiceProvider.
        'http' => [
            'timeout'         => env('NASTARI_HTTP_TIMEOUT', 20),
            'connect_timeout' => env('NASTARI_HTTP_CONNECT_TIMEOUT', 5),

            // A call slower than this is recorded as a slow-call event even
            // when it eventually succeeds.
            'slow_ms' => env('NASTARI_HTTP_SLOW_MS', 5000),
        ],

        /*
        | Pemindaian file log (NastariLogScanner -> nastari_log_entries).
        |
        | Event terstruktur hanya berisi apa yang berhasil dicatat aplikasi.
        | Yang tidak pernah sampai ke sana: fatal error PHP (prosesnya mati
        | sebelum bisa menulis), exception di panel admin, dan setiap
        | Log::error yang tidak punya padanan event. Semua itu hanya ada di
        | storage/logs, dan file log dihapus setiap 14 hari — jadi dipindai
        | dan disimpan agar tetap bisa ditelusuri lewat dashboard.
        */
        'files' => [
            // Dibuat konfigurabel supaya bisa diuji tanpa menulis ke
            // storage/logs milik aplikasi yang sedang berjalan.
            'path'    => env('NASTARI_LOG_PATH', storage_path('logs')),
            'pattern' => 'laravel*.log',

            // Hanya level yang benar-benar butuh tindakan. WARNING sengaja
            // tidak diikutkan: LETTERHEAD_SKIPPED_OVER_BUDGET dan sejenisnya
            // adalah jalur normal, bukan insiden.
            'levels' => ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'],

            // Pesan dipotong: satu stack trace bisa puluhan kilobyte, dan yang
            // berguna untuk monitoring adalah baris pertamanya.
            'max_message' => 400,

            // Batas aman satu kali pindai, supaya file yang tiba-tiba
            // membengkak tidak membuat scheduler berjalan tanpa henti.
            'max_entries_per_scan' => 5000,

            // Tabelnya menyimpan lebih lama daripada file log itu sendiri.
            'retention_days' => 30,
        ],

        // Host fragment -> service name, used to attribute external failures.
        'services' => [
            'darwinbox.com'            => 'darwinbox',
            'apps.hcis.live'           => 'hcis',
            'graph.facebook.com'       => 'whatsapp',
            'generativelanguage.googleapis.com' => 'gemini',
            'openrouter.ai'            => 'openrouter',
            '127.0.0.1:5003'           => 'pandu_rag',
            'localhost:5003'           => 'pandu_rag',
        ],
    ],

    /*
    | Generated letter files.
    */
    'letters' => [
        'output_path'   => storage_path('app/surat-keterangan'),
        'retention_days' => 30,

        // Business unit -> letter number code. Keyed on the values that
        // actually exist in kpncorp.employees.group_company.
        'bu_codes' => [
            'KPN Corporation' => 'CHC',
            'Corporate'       => 'CHC',
            'Downstream'      => 'DWS',
            'Cement'          => 'CMT',
            'Plantations'     => 'PLT',
            'Plantation'      => 'PLT',
            'Property'        => 'PTY',
            'KPN Sugar'       => 'SGR',
        ],
        'bu_code_fallback' => 'CHC',
    ],

];
