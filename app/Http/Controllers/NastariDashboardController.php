<?php

namespace App\Http\Controllers;

use App\Models\CallCenter;
use App\Models\Role;
use App\Services\NastariAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard monitoring Nastari — satu halaman, delapan bagian.
 *
 * Menggantikan dua halaman sebelumnya (dashboard lama + halaman Analitik
 * tersendiri) dan menjawab kebutuhan yang diminta, dengan urutan yang sama:
 *
 *   (a) total pengguna terdaftar, aktif vs nonaktif      -> #pengguna
 *   (b) status sinkronisasi harian dari kpncorp.employees -> #sinkronisasi
 *   (c) jam sibuk dengan filter periode                   -> #jam-sibuk
 *   (d) nomor yang mengakses tapi tidak ditemukan         -> #nomor-asing
 *   (e) riwayat percakapan Ruang                          -> #ruang
 *   (f) distribusi unit bisnis (dari data, bukan hardcode)-> #unit-bisnis
 *   (g) error dan layanan eksternal                       -> #kesehatan
 *   (h) statistik akses fitur, termasuk per karyawan      -> #fitur
 *
 * Pandu punya bagiannya sendiri (#pandu): ringkasan, tren harian, pertanyaan
 * yang berulang, dan riwayat tanya-jawabnya. Isi jawaban dibaca dari pandu_qa,
 * bukan dari `hears` — tabel itu hanya menerima eskalasi, jadi mengukur Pandu
 * dari sana berarti hanya melihat kegagalannya.
 *
 * Ditambah satu blok yang bukan metrik melainkan diagnostik: entri ERROR ke
 * atas dari storage/logs (#log-aplikasi), hasil pemindaian NastariLogScanner.
 * Fatal error PHP hanya ada di sana — prosesnya mati sebelum pencatat aktivitas
 * bisa menulis apa pun — jadi tanpa blok ini kelas kegagalan paling berat
 * justru yang tidak kelihatan di dashboard.
 *
 * Read-only: tidak ada satu pun query di sini yang menulis.
 *
 * CATATAN PERFORMA. Halaman ini memuat semua bagian sekaligus, jadi datanya
 * di-cache dalam enam kelompok terpisah, bukan satu payload besar. Kalau
 * digabung, mengganti halaman tabel Ruang akan membatalkan cache seluruh
 * halaman dan memaksa hitung ulang termasuk query ke database korporat yang
 * remote. Kunci cache setiap kelompok hanya memuat filter yang memang
 * memengaruhi kelompok itu.
 */
class NastariDashboardController extends Controller
{
    public function __construct(private NastariAnalyticsService $analytics)
    {
    }

    /** Preset periode untuk kebutuhan (c): hari ini / 7 hari / 30 hari. */
    private const RANGES = ['today' => 0, '7d' => 6, '30d' => 29, '90d' => 89];

    /**
     * 5 menit. Cukup dekat dengan waktu nyata untuk monitoring, dan cukup lama
     * untuk membuat halaman ini praktis selalu hangat: satu kali muat dingin
     * berharga ~50 query ke database yang latensinya ~45 ms per query.
     */
    private const CACHE_TTL = 300;

    public function index(Request $request)
    {
        [$from, $to, $range] = $this->resolveRange($request);

        $window = $this->analytics->window($from, $to);
        $bu = $request->query('bu') ?: null;

        // Unit bisnis yang dikirim lewat query string harus benar-benar ada di
        // data. Tanpa ini, ?bu=<apa saja> menghasilkan halaman kosong yang
        // terlihat seperti "tidak ada aktivitas" padahal filternya salah.
        $buList = $this->analytics->availableBusinessUnits();

        if ($bu !== null && ! in_array($bu, $buList, true)) {
            $bu = null;
        }

        $keyFor = fn (string $group, array $parts) => 'nastari_dash:' . $group . ':' . md5(implode('|', $parts));

        // --- kelompok 1: agregat inti (a, b, c, f, g, h) ------------------
        $core = Cache::remember(
            $keyFor('core', [$window['from'], $window['to'], (string) $bu]),
            self::CACHE_TTL,
            function () use ($window, $bu) {
                $features = $this->analytics->featureRanking($window, $bu);
                $hours = $this->analytics->byHour($window, $bu);

                return [
                    'coverage' => $this->analytics->registrationCoverage($bu),
                    'overview' => $this->analytics->overview($window, $bu),
                    'sync'     => $this->analytics->syncStatus(),
                    'daily'    => $this->analytics->dailySeries($window, $bu),
                    'hours'    => $hours,
                    'peak'     => $this->analytics->peakHour($hours),
                    'dow'      => $this->analytics->byDayOfWeek($window, $bu),
                    'bus'      => $this->analytics->businessUnits($window),
                    'features' => $features,
                    'trend'    => $this->analytics->featureTrend(
                        $window,
                        array_slice(array_column($features, 'key'), 0, 5),
                        $bu
                    ),
                    'denials'  => $this->analytics->accessDenials($window, $bu),
                    'health'   => $this->analytics->health($window),
                    'logs'     => $this->analytics->logSummary($window),
                    'pandu'    => $this->analytics->panduOverview($window, $bu),
                    'panduDaily'     => $this->analytics->panduDaily($window, $bu),
                    'panduTop'       => $this->analytics->panduTopQuestions($window, $bu),
                    'panduTickets'   => $this->analytics->panduOpenTickets(10),
                ];
            }
        );

        // --- kelompok 2: nomor tidak dikenal (d) --------------------------
        // Tidak pernah difilter unit bisnis: nomor yang gagal dikenali belum
        // punya unit bisnis sama sekali.
        $unknown = Cache::remember(
            $keyFor('unknown', [$window['from'], $window['to']]),
            self::CACHE_TTL,
            fn () => $this->analytics->unknownNumbers($window, 100)
        );

        // --- kelompok 3: riwayat Ruang (e), dipaginasi --------------------
        $ruangPage = max(1, (int) $request->query('ruang_page', 1));
        $ruangStatus = in_array($request->query('status'), ['ok', 'error'], true)
            ? $request->query('status')
            : null;

        $ruang = Cache::remember(
            $keyFor('ruang', [$window['from'], $window['to'], (string) $bu, (string) $ruangPage, (string) $ruangStatus]),
            self::CACHE_TTL,
            fn () => $this->analytics->ruangConversations($window, $bu, $ruangStatus, 20, $ruangPage)
        );

        // --- kelompok 4: tanya-jawab Pandu, dipaginasi --------------------
        $panduPage = max(1, (int) $request->query('pandu_page', 1));
        $panduOutcome = in_array($request->query('pandu_outcome'), ['answered', 'unanswered', 'escalated'], true)
            ? $request->query('pandu_outcome')
            : null;
        $panduSearch = $request->query('pandu_q');
        $panduSearch = is_string($panduSearch) && trim($panduSearch) !== ''
            ? substr(trim($panduSearch), 0, 80)
            : null;

        $panduQa = Cache::remember(
            $keyFor('panduqa', [
                $window['from'], $window['to'], (string) $bu, (string) $panduOutcome,
                (string) $panduSearch, (string) $panduPage,
            ]),
            self::CACHE_TTL,
            fn () => $this->analytics->panduConversations($window, $bu, $panduOutcome, $panduSearch, 15, $panduPage)
        );

        // --- kelompok 5: entri log aplikasi, dipaginasi -------------------
        // Tidak difilter unit bisnis: sebuah fatal error PHP tidak punya
        // pemilik unit bisnis, dan sebagian entri datang dari panel admin yang
        // tidak lewat jalur WhatsApp sama sekali.
        $logPage = max(1, (int) $request->query('log_page', 1));
        $logLevel = in_array(strtoupper((string) $request->query('log_level')), ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)
            ? strtoupper((string) $request->query('log_level'))
            : null;

        $logs = Cache::remember(
            $keyFor('logrows', [$window['from'], $window['to'], (string) $logLevel, (string) $logPage]),
            self::CACHE_TTL,
            fn () => $this->analytics->logEntries($window, $logLevel, null, 15, $logPage)
        );

        // --- kelompok 6: akses fitur per karyawan (h), dipaginasi ---------
        $empPage = max(1, (int) $request->query('emp_page', 1));
        $empSearch = $request->query('q');
        $empSearch = is_string($empSearch) && trim($empSearch) !== '' ? substr(trim($empSearch), 0, 60) : null;

        // Tabel ini satu-satunya yang menampilkan data per orang beserta NIK,
        // jadi tetap dibatasi scope unit bisnis milik role, sementara angka
        // agregat di halaman ini bersifat korporat.
        $scopeBus = $this->scopedBusinessUnits();

        $employees = Cache::remember(
            $keyFor('emp', [
                $window['from'], $window['to'], (string) $bu, (string) $empSearch,
                (string) $empPage, implode(',', $scopeBus),
            ]),
            self::CACHE_TTL,
            fn () => $this->analytics->employeeFeatureUsage($window, $bu, $empSearch, 25, $empPage, $scopeBus)
        );

        return view('admin.dashboard.index', array_merge($core, [
            'window'        => $window,
            'range'         => $range,
            'bu'            => $bu,
            'buList'        => $buList,
            'bounds'        => $this->analytics->dataBounds(),
            'unknown'       => $unknown,
            'ruang'         => $ruang,
            'ruangStatus'   => $ruangStatus,
            'employees'     => $employees,
            'empSearch'     => $empSearch,
            'logRows'       => $logs,
            'logLevel'      => $logLevel,
            'panduQa'       => $panduQa,
            'panduOutcome'  => $panduOutcome,
            'panduSearch'   => $panduSearch,
            'scoped'        => $scopeBus !== [],
            'canSeeContent' => $this->canSeeConversations(),
            'httpFallback'  => (string) config('nastari.tasks.token', '') !== '',
        ], $this->carriedOver()));
    }

    /**
     * Dua widget yang dipertahankan dari dashboard lama supaya tidak ada yang
     * hilang saat halaman ini menggantikannya: jumlah tiket HC System Desk dan
     * insight mingguan Ruang per unit bisnis.
     *
     * Keduanya tetap memakai scope role persis seperti sebelumnya — bukan
     * korporat — supaya tidak ada perubahan siapa boleh melihat apa.
     *
     * Di-cache per pengguna karena hasilnya bergantung pada scope role, dan
     * jumlah query-nya tumbuh mengikuti banyaknya unit bisnis dalam scope itu
     * (satu query chat_insights per unit). Tanpa cache, bagian yang paling
     * jarang berubah di halaman ini justru yang paling sering diquery: insight
     * mingguan hanya diperbarui sekali seminggu.
     *
     * @return array<string, mixed>
     */
    private function carriedOver(): array
    {
        $user = Auth::user();
        $key = 'nastari_dash:carried:' . ($user->employee_id ?? 'anon');

        return Cache::remember($key, self::CACHE_TTL, fn () => $this->computeCarriedOver());
    }

    /** @return array<string, mixed> */
    private function computeCarriedOver(): array
    {
        $hcDesk = 0;

        try {
            $hcDesk = CallCenter::join('usernastari', 'call_centers.employee_id', '=', 'usernastari.employee_id')
                ->scoped()
                ->count('call_centers.id');
        } catch (\Throwable) {
            $hcDesk = 0;
        }

        $allowedBUs = \App\Models\UserNastari::scoped()->registered()
            ->whereNotNull('group_company')->where('group_company', '!=', '')
            ->distinct()->pluck('group_company')->toArray();

        $buInsights = [];

        foreach ($allowedBUs as $unit) {
            $insight = DB::table('chat_insights')
                ->where('business_unit', $unit)
                ->where('period_type', 'weekly')
                ->latest('end_date')
                ->first();

            if ($insight) {
                $insight->top_topics = json_decode($insight->top_topics, true);
            }

            $buInsights[$unit] = $insight;
        }

        return [
            'totalHCDesk' => $hcDesk,
            'allowedBUs'  => $allowedBUs,
            'buInsights'  => $buInsights,
        ];
    }

    /**
     * Unit bisnis yang boleh dilihat role saat ini.
     *
     * Mengikuti aturan ScopedByRole: "Super Admin" (case-insensitive) tanpa
     * batas, tanpa role sama sekali berarti tidak boleh melihat apa pun, dan
     * scope_bu dipetakan ke group_company — kolom yang sama yang dipakai trait.
     * Nilai scope disimpan longgar (JSON, koma, atau string tunggal) dan
     * dicocokkan sebagai potongan teks, jadi pencocokannya dilakukan terhadap
     * daftar unit bisnis yang nyata ada di data.
     *
     * @return array<int, string>  Kosong berarti tanpa batasan.
     */
    private function scopedBusinessUnits(): array
    {
        $user = Auth::user();

        if (! $user || empty($user->employee_id)) {
            return ['-tanpa-akses-'];
        }

        $roleIds = DB::table('role_user')->where('user_id', $user->employee_id)->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return ['-tanpa-akses-'];
        }

        $roles = Role::whereIn('id', $roleIds)->get();

        if ($roles->contains(fn ($r) => strtolower((string) $r->name) === 'super admin')) {
            return [];
        }

        $fragments = [];

        foreach ($roles as $role) {
            foreach ($this->parseScope($role->scope_bu) as $fragment) {
                if (trim((string) $fragment) !== '') {
                    $fragments[] = trim((string) $fragment);
                }
            }
        }

        // Role yang dibatasi lewat company/location saja tidak bisa dipetakan
        // ke unit bisnis, jadi biarkan seperti perilaku halaman analitik lama.
        if ($fragments === []) {
            return [];
        }

        $matched = [];

        foreach ($this->analytics->availableBusinessUnits() as $unit) {
            foreach ($fragments as $fragment) {
                if (stripos($unit, $fragment) !== false) {
                    $matched[] = $unit;
                    break;
                }
            }
        }

        return $matched === [] ? ['-tanpa-akses-'] : array_values(array_unique($matched));
    }

    /** @return array<int, string> */
    private function parseScope($data): array
    {
        if (empty($data)) {
            return [];
        }

        if (is_array($data)) {
            return $data;
        }

        $decoded = json_decode((string) $data, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (str_contains((string) $data, ',')) {
            return array_map('trim', explode(',', (string) $data));
        }

        return [trim((string) $data)];
    }

    /**
     * Menerjemahkan ?range=today|7d|30d|90d menjadi jendela tanggal.
     *
     * ?from/&to eksplisit selalu menang, supaya tautan yang sudah dibookmark
     * tetap bekerja. Preset selalu berpatokan pada hari ini, bukan pada tanggal
     * terakhir yang ada datanya: "hari ini" harus berarti hari ini walaupun
     * belum ada aktivitas apa pun.
     *
     * @return array{0:?string, 1:?string, 2:?string}
     */
    private function resolveRange(Request $request): array
    {
        $from = $this->safeDate($request->query('from'));
        $to = $this->safeDate($request->query('to'));

        if ($from !== null || $to !== null) {
            return [$from, $to, null];
        }

        $range = (string) $request->query('range', '30d');

        if (! array_key_exists($range, self::RANGES)) {
            $range = '30d';
        }

        $today = \Carbon\Carbon::today();

        return [
            $today->copy()->subDays(self::RANGES[$range])->toDateString(),
            $today->toDateString(),
            $range,
        ];
    }

    /**
     * ?from=/&to= yang tidak valid tidak boleh sampai ke Carbon::parse.
     *
     * createFromFormat saja tidak cukup: bagian tanggal di luar rentang tidak
     * ditolak melainkan digulung, sehingga "2026-13-45" dulu terbaca bersih
     * menjadi jendela di masa depan. Perbandingan bolak-balik inilah yang
     * benar-benar menolaknya.
     */
    private function safeDate($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $parsed = \Carbon\Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Transkrip Ruang berisi cerita pribadi, kadang berat, jadi berada di
     * balik pemeriksaan yang lebih ketat daripada halaman agregat.
     */
    public function transcript(int $id): JsonResponse
    {
        if (! $this->canSeeConversations()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Anda tidak memiliki akses untuk membuka transkrip percakapan.',
            ], 403);
        }

        $transcript = $this->analytics->ruangTranscript($id);

        if ($transcript === null) {
            return response()->json(['ok' => false, 'message' => 'Percakapan tidak ditemukan.'], 404);
        }

        return response()->json(['ok' => true, 'data' => $transcript]);
    }

    private function canSeeConversations(): bool
    {
        $user = Auth::user();

        if (! $user || empty($user->employee_id)) {
            return false;
        }

        return DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->employee_id)
            ->whereRaw('LOWER(roles.name) = ?', ['super admin'])
            ->exists();
    }
}
