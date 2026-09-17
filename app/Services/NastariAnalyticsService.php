<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every metric on the Nastari analytics pages.
 *
 * Reads nastari_events (behaviour) and joins the operational tables that hold
 * outcomes: usernastari (registration), kpncorp.employees (headcount),
 * letter_logs (completions), hears (Pandu escalations), daily_chat_logs (Ruang).
 *
 * METRIC VOCABULARY — used consistently everywhere:
 *
 *   action        a menu selection that returns data or completes a task.
 *                 event_type IN (feature_selected, feature_completed,
 *                 pandu_escalation_*). This is the headline usage number.
 *   navigation    getting around: menu_opened, navigation (back), session_end.
 *                 Counted separately: high navigation per action is friction,
 *                 not usage.
 *   message       any inbound WhatsApp message (source = wa_incoming). Raw
 *                 volume, including greetings and stray text.
 *   active user   COUNT DISTINCT phone with >= 1 message, restricted to numbers
 *                 that resolve to an employee.
 *   unknown       a number that failed employee lookup. Distinct numbers and
 *                 attempt counts are always reported as separate figures.
 */
class NastariAnalyticsService
{
    private const CACHE_TTL = 600;

    /** Per-request memo: insights() reuses overview/features/pandu wholesale. */
    private array $memo = [];

    private function once(string $key, \Closure $fn)
    {
        return $this->memo[$key] ??= $fn();
    }

    public const ACTION_TYPES = [
        'feature_selected',
        'feature_completed',
        'pandu_escalation_accepted',
        'pandu_escalation_declined',
    ];

    public const NAV_TYPES = ['menu_opened', 'navigation', 'session_end'];

    /** Features that are entry points rather than data lookups. */
    private const ENTRY_FEATURES = ['deeplink_ruang', 'deeplink_intro'];

    // ------------------------------------------------------------------
    // window helpers
    // ------------------------------------------------------------------

    /** @return array{from:string, to:string, days:int, label:string} */
    public function window(?string $from, ?string $to): array
    {
        $bounds = $this->dataBounds();

        $to = $to ?: ($bounds['max'] ?? Carbon::today()->toDateString());
        $from = $from ?: Carbon::parse($to)->subDays(29)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from'  => $from,
            'to'    => $to,
            'days'  => Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
            'label' => Carbon::parse($from)->format('d M Y') . ' – ' . Carbon::parse($to)->format('d M Y'),
        ];
    }

    /** @return array{min:?string, max:?string, rows:int, last_ingest:?string} */
    public function dataBounds(): array
    {
        return Cache::remember('nastari_analytics_bounds', self::CACHE_TTL, function () {
            $r = DB::table('nastari_events')->selectRaw('MIN(event_date) mn, MAX(event_date) mx, COUNT(*) c')->first();
            $run = DB::table('nastari_ingest_runs')->where('status', 'ok')->latest('finished_at')->first();

            return [
                'min'         => $r->mn ?? null,
                'max'         => $r->mx ?? null,
                'rows'        => (int) ($r->c ?? 0),
                'last_ingest' => $run->finished_at ?? null,
            ];
        });
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function events(array $w, ?string $bu = null)
    {
        $q = DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']]);

        if ($bu !== null && $bu !== '') {
            $q->where('business_unit', $bu);
        }

        return $q;
    }

    // ------------------------------------------------------------------
    // 1. Overview
    // ------------------------------------------------------------------

    public function overview(array $w, ?string $bu = null): array
    {
        return $this->once('overview:' . $w['from'] . $w['to'] . $bu, fn () => $this->computeOverview($w, $bu));
    }

    private function computeOverview(array $w, ?string $bu = null): array
    {
        $headcount = $this->headcount($bu);
        $registered = $this->registeredUsers($bu);

        // Empat angka ini dulunya empat query terpisah dengan filter tanggal
        // dan unit bisnis yang sama persis. Digabung menjadi satu agregat
        // kondisional: hasilnya identik, tapi ongkos jaringannya seperempat —
        // dan di database ini latensi per query jauh lebih besar daripada
        // waktu eksekusinya.
        $agg = (clone $this->events($w, $bu))
            ->selectRaw('SUM(source = "wa_incoming") messages')
            ->selectRaw('SUM(event_type IN ("' . implode('","', self::ACTION_TYPES) . '")) actions')
            ->selectRaw('SUM(event_type IN ("' . implode('","', self::NAV_TYPES) . '")) navigation')
            ->selectRaw('COUNT(DISTINCT CASE WHEN source = "wa_incoming" AND is_known_employee = 1 THEN phone END) active_users')
            ->first();

        $messages = (int) ($agg->messages ?? 0);
        $actions = (int) ($agg->actions ?? 0);
        $navigation = (int) ($agg->navigation ?? 0);
        $activeUsers = (int) ($agg->active_users ?? 0);

        // Unknown numbers are never BU-filtered: an unidentified number has no BU.
        $unknown = DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->where('event_type', 'employee_lookup_failed')
            ->selectRaw('COUNT(*) attempts, COUNT(DISTINCT phone) numbers')
            ->first();

        $unknownNumbers = (int) ($unknown->numbers ?? 0);
        $unknownAttempts = (int) ($unknown->attempts ?? 0);

        $newUsers = $this->newUserCount($w, $bu);

        return [
            'total_employees'   => $headcount,
            'registered_users'  => $registered,
            'active_users'      => $activeUsers,
            'new_users'         => $newUsers,
            'returning_users'   => max(0, $activeUsers - $newUsers),
            'messages'          => $messages,
            'actions'           => $actions,
            'navigation'        => $navigation,
            'nav_per_action'    => $actions > 0 ? round($navigation / $actions, 2) : null,
            'actions_per_user'  => $activeUsers > 0 ? round($actions / $activeUsers, 1) : 0,
            'unknown_numbers'   => $unknownNumbers,
            'unknown_attempts'  => $unknownAttempts,
            'registration_rate' => $headcount > 0 ? round(100 * $registered / $headcount, 1) : null,
            'active_rate'       => $headcount > 0 ? round(100 * $activeUsers / $headcount, 1) : null,
        ];
    }

    /** Live headcount: soft-deleted employee rows are excluded. */
    public function headcount(?string $bu = null): int
    {
        return Cache::remember('nastari_headcount_' . ($bu ?: 'all'), self::CACHE_TTL, function () use ($bu) {
            try {
                $q = DB::connection('kpncorp')->table('employees')->whereNull('deleted_at');
                if ($bu) {
                    $q->where('group_company', $bu);
                }

                return (int) $q->count();
            } catch (\Throwable) {
                return 0;
            }
        });
    }

    /**
     * People who have actually used the bot.
     *
     * usernastari also holds rows pre-registered from HRIS by the daily
     * employee sync. Counting those here would turn an adoption metric into a
     * headcount metric, so they are excluded and reported separately by
     * registrationCoverage().
     */
    public function registeredUsers(?string $bu = null): int
    {
        $q = DB::table('usernastari')->where('registration_status', 'registered');
        if ($bu) {
            $q->where('group_company', $bu);
        }

        return (int) $q->count();
    }

    /**
     * The active/inactive split the dashboard leads with, plus HRIS coverage.
     *
     * @return array<string, int|float|null>
     */
    public function registrationCoverage(?string $bu = null): array
    {
        return Cache::remember('nastari_reg_coverage_' . ($bu ?: 'all'), self::CACHE_TTL, function () use ($bu) {
            $base = fn () => DB::table('usernastari')->when($bu, fn ($q) => $q->where('group_company', $bu));

            // Satu query bergrup menggantikan lima COUNT terpisah. Hanya tiga
            // nilai status yang dikenali, sama seperti sebelumnya: baris dengan
            // status di luar itu tidak masuk hitungan mana pun, termasuk total.
            $grouped = (clone $base())
                ->selectRaw('registration_status, employment_status, COUNT(*) c')
                ->groupBy('registration_status', 'employment_status')
                ->get();

            $tally = ['active' => 0, 'inactive' => 0, 'unknown' => 0];
            $preRegistered = 0;

            foreach ($grouped as $row) {
                if ($row->registration_status === 'pre_registered') {
                    $preRegistered += (int) $row->c;

                    continue;
                }

                if ($row->registration_status === 'registered'
                    && array_key_exists((string) $row->employment_status, $tally)) {
                    $tally[(string) $row->employment_status] += (int) $row->c;
                }
            }

            $active = $tally['active'];
            $inactive = $tally['inactive'];
            $unknown = $tally['unknown'];
            $total = $active + $inactive + $unknown;

            $headcount = $this->headcount($bu);

            $lastSync = DB::table('employee_sync_runs')
                ->where('status', 'ok')->latest('finished_at')->value('finished_at');

            return [
                'registered'        => $total,
                'active'            => $active,
                'inactive'          => $inactive,
                'unknown'           => $unknown,
                'pre_registered'    => $preRegistered,
                'headcount_active'  => $headcount,
                'coverage_pct'      => $headcount > 0 ? round(100 * $total / $headcount, 1) : null,
                'last_sync_at'      => $lastSync,
            ];
        });
    }

    /**
     * New = first event ever recorded for that number falls inside the window.
     * Left-censored at the earliest ingested date, which is reported alongside.
     */
    private function newUserCount(array $w, ?string $bu = null): int
    {
        $sub = DB::table('nastari_events')
            ->selectRaw('phone, MIN(event_date) first_seen')
            ->whereNotNull('phone')
            ->where('is_known_employee', true);

        if ($bu) {
            $sub->where('business_unit', $bu);
        }

        return (int) DB::query()
            ->fromSub($sub->groupBy('phone'), 'f')
            ->whereBetween('first_seen', [$w['from'], $w['to']])
            ->count();
    }

    // ------------------------------------------------------------------
    // 2. Usage over time
    // ------------------------------------------------------------------

    public function dailySeries(array $w, ?string $bu = null): array
    {
        $rows = (clone $this->events($w, $bu))
            ->where('source', 'wa_incoming')
            ->selectRaw('event_date, COUNT(DISTINCT phone) users, COUNT(*) messages')
            ->selectRaw('SUM(event_type IN ("' . implode('","', self::ACTION_TYPES) . '")) actions')
            ->groupBy('event_date')
            ->orderBy('event_date')
            ->get()
            ->keyBy('event_date');

        $out = [];
        $cursor = Carbon::parse($w['from']);
        $end = Carbon::parse($w['to']);

        while ($cursor <= $end) {
            $d = $cursor->toDateString();
            $r = $rows->get($d);
            $out[] = [
                'date'     => $d,
                'label'    => $cursor->format('d M'),
                'users'    => (int) ($r->users ?? 0),
                'messages' => (int) ($r->messages ?? 0),
                'actions'  => (int) ($r->actions ?? 0),
                'has_log'  => $r !== null,
            ];
            $cursor->addDay();
        }

        return $out;
    }

    /** Actions per user, bucketed. Buckets chosen to match observed spread. */
    public function frequencySegments(array $w, ?string $bu = null): array
    {
        $perUser = (clone $this->events($w, $bu))
            ->whereIn('event_type', self::ACTION_TYPES)
            ->where('is_known_employee', true)
            ->selectRaw('phone, COUNT(*) n')
            ->groupBy('phone')
            ->pluck('n');

        $buckets = [
            '1 aksi'    => fn ($n) => $n == 1,
            '2–5'       => fn ($n) => $n >= 2 && $n <= 5,
            '6–10'      => fn ($n) => $n >= 6 && $n <= 10,
            '11–20'     => fn ($n) => $n >= 11 && $n <= 20,
            '20+'       => fn ($n) => $n > 20,
        ];

        $out = [];
        $total = $perUser->count();

        foreach ($buckets as $label => $test) {
            $c = $perUser->filter($test)->count();
            $out[] = [
                'label' => $label,
                'users' => $c,
                'share' => $total > 0 ? round(100 * $c / $total, 1) : 0,
            ];
        }

        return ['segments' => $out, 'total_users' => $total];
    }

    /**
     * Jam sibuk. Satu query: pesan dan nomor unik dihitung dalam agregat yang
     * sama, bukan dua query yang mengulang GROUP BY dan filter yang identik.
     */
    public function byHour(array $w, ?string $bu = null): array
    {
        $rows = (clone $this->events($w, $bu))
            ->where('source', 'wa_incoming')
            ->selectRaw('event_hour, COUNT(*) messages, COUNT(DISTINCT phone) users')
            ->groupBy('event_hour')->get();

        // Dikunci sebagai integer: driver bisa mengembalikan kolom jam sebagai
        // string, dan pencarian dengan kunci integer akan luput.
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->event_hour] = $row;
        }

        $out = [];

        for ($h = 0; $h < 24; $h++) {
            $out[] = [
                'hour'     => $h,
                'label'    => sprintf('%02d:00', $h),
                'messages' => (int) ($map[$h]->messages ?? 0),
                'users'    => (int) ($map[$h]->users ?? 0),
            ];
        }

        return $out;
    }

    public function byDayOfWeek(array $w, ?string $bu = null): array
    {
        $names = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

        $rows = (clone $this->events($w, $bu))->where('source', 'wa_incoming')
            ->selectRaw('event_dow, COUNT(*) messages, COUNT(DISTINCT phone) users')
            ->groupBy('event_dow')->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->event_dow] = $row;
        }

        $out = [];

        foreach ($names as $i => $name) {
            $out[] = [
                'dow'      => $i,
                'label'    => $name,
                'messages' => (int) ($map[$i]->messages ?? 0),
                'users'    => (int) ($map[$i]->users ?? 0),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 3. Business unit
    // ------------------------------------------------------------------

    public function businessUnits(array $w): array
    {
        return $this->once('bus:' . $w['from'] . $w['to'], fn () => $this->computeBusinessUnits($w));
    }

    private function computeBusinessUnits(array $w): array
    {
        $headcount = [];
        $inactiveHeadcount = [];

        try {
            // Business units are read from the data, never hardcoded: the live
            // values are Cement, Downstream, Plantations, KPN Corporation,
            // Property and KPN Sugar, and that list changes without notice.
            $headcount = DB::connection('kpncorp')->table('employees')
                ->whereNull('deleted_at')->whereNotNull('group_company')
                ->selectRaw('group_company bu, COUNT(*) c')->groupBy('group_company')
                ->pluck('c', 'bu')->all();

            $inactiveHeadcount = DB::connection('kpncorp')->table('employees')
                ->whereNotNull('deleted_at')->whereNotNull('group_company')
                ->selectRaw('group_company bu, COUNT(*) c')->groupBy('group_company')
                ->pluck('c', 'bu')->all();
        } catch (\Throwable) {
            $headcount = [];
            $inactiveHeadcount = [];
        }

        $registered = DB::table('usernastari')->whereNotNull('group_company')
            ->where('registration_status', 'registered')
            ->selectRaw('group_company bu, COUNT(*) c')->groupBy('group_company')->pluck('c', 'bu')->all();

        $registeredInactive = DB::table('usernastari')->whereNotNull('group_company')
            ->where('registration_status', 'registered')
            ->where('employment_status', 'inactive')
            ->selectRaw('group_company bu, COUNT(*) c')->groupBy('group_company')->pluck('c', 'bu')->all();

        $ev = DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->where('source', 'wa_incoming')->whereNotNull('business_unit')
            ->selectRaw('business_unit bu, COUNT(DISTINCT phone) users')
            ->selectRaw('SUM(event_type IN ("' . implode('","', self::ACTION_TYPES) . '")) actions')
            ->groupBy('business_unit')->get()->keyBy('bu');

        $out = [];
        $allBus = array_unique(array_merge(
            array_keys($headcount),
            array_keys($inactiveHeadcount),
            array_keys($registered)
        ));

        foreach ($allBus as $bu) {
            $emp = (int) ($headcount[$bu] ?? 0);
            $empInactive = (int) ($inactiveHeadcount[$bu] ?? 0);
            $reg = (int) ($registered[$bu] ?? 0);
            $regInactive = (int) ($registeredInactive[$bu] ?? 0);
            $active = (int) ($ev[$bu]->users ?? 0);
            $actions = (int) ($ev[$bu]->actions ?? 0);

            $out[] = [
                'bu'                 => $bu,
                'employees'          => $emp,
                'employees_inactive' => $empInactive,
                'registered'         => $reg,
                'registered_inactive' => $regInactive,
                'registration_rate'  => $emp > 0 ? round(100 * $reg / $emp, 1) : null,
                'active_users'       => $active,
                'active_rate'        => $emp > 0 ? round(100 * $active / $emp, 1) : null,
                'actions'            => $actions,
                'actions_per_user'   => $active > 0 ? round($actions / $active, 1) : 0,
            ];
        }

        usort($out, fn ($a, $b) => $b['employees'] <=> $a['employees']);

        return $out;
    }

    // ------------------------------------------------------------------
    // 4. Features
    // ------------------------------------------------------------------

    public function featureRanking(array $w, ?string $bu = null): array
    {
        return $this->once('feat:' . $w['from'] . $w['to'] . $bu, fn () => $this->computeFeatureRanking($w, $bu));
    }

    private function computeFeatureRanking(array $w, ?string $bu = null): array
    {
        $rows = (clone $this->events($w, $bu))
            ->whereIn('event_type', ['feature_selected', 'feature_completed'])
            ->whereNotNull('feature_key')
            ->whereNotIn('feature_key', self::ENTRY_FEATURES)
            ->selectRaw('feature_key, MAX(feature_label) label, COUNT(*) uses, COUNT(DISTINCT phone) users')
            ->groupBy('feature_key')
            ->orderByDesc('uses')
            ->get();

        $total = $rows->sum('uses') ?: 1;
        $activeUsers = (clone $this->events($w, $bu))->where('source', 'wa_incoming')
            ->where('is_known_employee', true)->distinct()->count('phone') ?: 1;

        return $rows->map(fn ($r, $i) => [
            'rank'       => $i + 1,
            'key'        => $r->feature_key,
            'label'      => $r->label,
            'uses'       => (int) $r->uses,
            'users'      => (int) $r->users,
            'share'      => round(100 * $r->uses / $total, 1),
            'reach'      => round(100 * $r->users / $activeUsers, 1),
            'per_user'   => round($r->uses / max(1, $r->users), 2),
        ])->values()->all();
    }

    public function featureTrend(array $w, array $keys, ?string $bu = null): array
    {
        if ($keys === []) {
            return [];
        }

        $rows = (clone $this->events($w, $bu))
            ->where('event_type', 'feature_selected')
            ->whereIn('feature_key', $keys)
            ->selectRaw('event_date, feature_key, COUNT(*) c')
            ->groupBy('event_date', 'feature_key')->get();

        $series = [];
        foreach ($keys as $k) {
            $series[$k] = [];
        }

        $cursor = Carbon::parse($w['from']);
        $end = Carbon::parse($w['to']);
        $byDate = $rows->groupBy('event_date');
        $labels = [];

        while ($cursor <= $end) {
            $d = $cursor->toDateString();
            $labels[] = $cursor->format('d M');
            $dayRows = $byDate->get($d, collect())->keyBy('feature_key');
            foreach ($keys as $k) {
                $series[$k][] = (int) ($dayRows[$k]->c ?? 0);
            }
            $cursor->addDay();
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * The only genuine funnel in the product: letters.
     * menu_opened(generate_surat) -> feature_selected(letter type) -> letter_logs row.
     * Every other feature answers immediately, so "opened" == "completed" and a
     * funnel there would be invented.
     */
    public function letterFunnel(array $w, ?string $bu = null): array
    {
        $opened = (clone $this->events($w, $bu))
            ->where('event_type', 'menu_opened')->where('feature_key', 'generate_surat')->count();

        $typeChosen = (clone $this->events($w, $bu))
            ->where('event_type', 'feature_selected')
            ->where(fn ($q) => $q->where('feature_key', 'like', 'surat%'))
            ->count();

        $completed = DB::table('letter_logs')
            ->whereBetween(DB::raw('DATE(created_at)'), [$w['from'], $w['to']])
            ->count();

        $byType = DB::table('letter_logs')
            ->whereBetween(DB::raw('DATE(created_at)'), [$w['from'], $w['to']])
            ->selectRaw('jenis_surat, COUNT(*) c, COUNT(DISTINCT nik) u')
            ->groupBy('jenis_surat')->orderByDesc('c')->get()
            ->map(fn ($r) => ['type' => $r->jenis_surat, 'count' => (int) $r->c, 'users' => (int) $r->u])
            ->all();

        return [
            'menu_opened'    => $opened,
            'type_chosen'    => $typeChosen,
            'completed'      => $completed,
            // Deliberately null when completions exceed observed starts: that
            // means the two sources disagree (letter_logs is not BU-scoped and
            // predates the event store), and a >100% funnel would mislead.
            'completion_pct' => ($typeChosen > 0 && $completed <= $typeChosen)
                ? round(100 * $completed / $typeChosen, 1)
                : null,
            'source_mismatch' => $completed > $typeChosen,
            'by_type'        => $byType,
            'note'           => 'letter_logs is BU-agnostic, so the completed figure is not filtered by business unit.',
        ];
    }

    // ------------------------------------------------------------------
    // 5. Pandu
    // ------------------------------------------------------------------

    public function pandu(array $w, ?string $bu = null): array
    {
        return $this->once('pandu:' . $w['from'] . $w['to'] . $bu, fn () => $this->computePandu($w, $bu));
    }

    private function computePandu(array $w, ?string $bu = null): array
    {
        $q = fn () => DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->where('event_type', 'pandu_question')
            ->when($bu, fn ($x) => $x->where('business_unit', $bu));

        $byOutcome = $q()->selectRaw('outcome, COUNT(*) c, ROUND(AVG(duration_ms)/1000,1) avg_s')
            ->groupBy('outcome')->get()->keyBy('outcome');

        $total = $q()->count();

        $questions = $q()->orderByDesc('occurred_at')->limit(200)
            ->get(['occurred_at', 'business_unit', 'outcome', 'duration_ms', 'payload'])
            ->map(function ($r) {
                $p = json_decode($r->payload ?? '{}', true);

                return [
                    'at'       => $r->occurred_at,
                    'bu'       => $r->business_unit,
                    'outcome'  => $r->outcome,
                    'seconds'  => $r->duration_ms ? round($r->duration_ms / 1000, 1) : null,
                    'question' => (string) ($p['question'] ?? ''),
                ];
            })->all();

        // Repeated questions, grouped on a normalised form of the text.
        $groups = [];
        foreach ($questions as $item) {
            $norm = $this->normaliseQuestion($item['question']);
            if ($norm === '') {
                continue;
            }
            $groups[$norm]['count'] = ($groups[$norm]['count'] ?? 0) + 1;
            $groups[$norm]['sample'] = $groups[$norm]['sample'] ?? $item['question'];
            $groups[$norm]['outcomes'][$item['outcome']] = ($groups[$norm]['outcomes'][$item['outcome']] ?? 0) + 1;
        }
        $repeated = collect($groups)->filter(fn ($g) => $g['count'] > 1)
            ->sortByDesc('count')->take(15)
            ->map(fn ($g) => [
                'question' => $g['sample'],
                'count'    => $g['count'],
                'outcomes' => $g['outcomes'],
            ])->values()->all();

        // Escalation queue from the operational table.
        $hears = DB::table('hears')->whereNull('deleted_at');
        $escTotal = (clone $hears)->count();
        $escOpen = (clone $hears)->where('status', '!=', 'Done')->count();
        $oldestOpen = (clone $hears)->where('status', '!=', 'Done')->min('date');

        $openTickets = (clone $hears)->where('status', '!=', 'Done')
            ->orderBy('date')->limit(25)
            ->get(['ticket_code', 'date', 'question', 'pic', 'status', 'name'])
            ->map(fn ($t) => [
                'code'     => $t->ticket_code,
                'date'     => $t->date,
                'age_days' => (int) Carbon::parse($t->date)->diffInDays(Carbon::now()),
                'question' => Str::limit((string) $t->question, 120),
                'pic'      => $t->pic ?: 'Belum ditugaskan',
                'status'   => $t->status,
            ])->all();

        return [
            'total'        => $total,
            'users'        => $q()->distinct()->count('phone'),
            'answered'     => (int) ($byOutcome['answered']->c ?? 0),
            'not_found'    => (int) ($byOutcome['not_found']->c ?? 0),
            'no_response'  => (int) ($byOutcome['no_response']->c ?? 0),
            'error'        => (int) ($byOutcome['error']->c ?? 0),
            'answer_rate'  => $total > 0 ? round(100 * (int) ($byOutcome['answered']->c ?? 0) / $total, 1) : null,
            'avg_seconds'  => $byOutcome['answered']->avg_s ?? null,
            'questions'    => $questions,
            'repeated'     => $repeated,
            'by_bu'        => $q()->whereNotNull('business_unit')
                                ->selectRaw('business_unit bu, COUNT(*) c')->groupBy('business_unit')
                                ->orderByDesc('c')->pluck('c', 'bu')->all(),
            'escalations'  => [
                'total'       => $escTotal,
                'open'        => $escOpen,
                'resolved'    => $escTotal - $escOpen,
                'open_pct'    => $escTotal > 0 ? round(100 * $escOpen / $escTotal, 1) : null,
                'oldest_open' => $oldestOpen,
                'oldest_age'  => $oldestOpen ? (int) Carbon::parse($oldestOpen)->diffInDays(Carbon::now()) : null,
                'tickets'     => $openTickets,
            ],
        ];
    }

    /** Cheap normalisation so "Bagaimana cara klaim kacamata?" variants group. */
    private function normaliseQuestion(string $q): string
    {
        $q = Str::lower(trim($q));
        $q = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q) ?? $q;
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;

        $stop = ['bagaimana', 'gimana', 'apa', 'apakah', 'saya', 'mau', 'ingin', 'tanya',
                 'menanyakan', 'mohon', 'info', 'tentang', 'cara', 'untuk', 'yang', 'dan',
                 'di', 'ke', 'dari', 'itu', 'ini', 'ya', 'yak', 'kah', 'nya', 'aku', 'tolong'];

        $words = array_filter(explode(' ', trim((string) $q)), fn ($w) => $w !== '' && ! in_array($w, $stop, true) && mb_strlen($w) > 2);
        sort($words);

        return implode(' ', array_slice(array_values($words), 0, 6));
    }

    // ------------------------------------------------------------------
    // 6. Ruang
    // ------------------------------------------------------------------

    public function ruang(array $w, ?string $bu = null): array
    {
        return $this->once('ruang:' . $w['from'] . $w['to'] . $bu, fn () => $this->computeRuang($w, $bu));
    }

    private function computeRuang(array $w, ?string $bu = null): array
    {
        $q = DB::table('daily_chat_logs')->whereBetween('date', [$w['from'], $w['to']]);
        if ($bu) {
            $q->where('business_unit', $bu);
        }

        $rows = (clone $q)->orderByDesc('date')
            ->get(['id', 'employee_id', 'employee_name', 'business_unit', 'phone', 'date', 'messages']);

        $totalMessages = 0;
        $userMessages = 0;
        $withDuration = 0;
        $conversations = [];
        $lengths = [];

        foreach ($rows as $r) {
            $msgs = json_decode($r->messages ?? '[]', true);
            $msgs = is_array($msgs) ? $msgs : [];
            $count = count($msgs);
            $totalMessages += $count;
            $lengths[] = $count;

            $times = array_values(array_filter(array_map(fn ($m) => $m['time'] ?? null, $msgs)));
            $duration = null;
            if (count($times) >= 2 && $times[0] !== end($times)) {
                $duration = max(0, strtotime(end($times)) - strtotime($times[0]));
                $withDuration++;
            }

            foreach ($msgs as $m) {
                if (($m['sender'] ?? '') === 'User') {
                    $userMessages++;
                }
            }

            $conversations[] = [
                'id'            => $r->id,
                'date'          => $r->date,
                'employee_name' => $r->employee_name,
                'bu'            => $r->business_unit,
                'phone_masked'  => $this->maskPhone((string) $r->phone),
                'messages'      => $count,
                'exchanges'     => intdiv($count, 2),
                'first_time'    => $times[0] ?? null,
                'last_time'     => $times ? end($times) : null,
                'duration_s'    => $duration,
            ];
        }

        sort($lengths);
        $entrants = DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->whereIn('feature_key', ['deeplink_ruang', 'ruang'])
            ->when($bu, fn ($x) => $x->where('business_unit', $bu))
            ->distinct()->count('phone');

        return [
            'conversation_days' => $rows->count(),
            'unique_users'      => $rows->pluck('employee_id')->unique()->count(),
            'total_messages'    => $totalMessages,
            'user_messages'     => $userMessages,
            'avg_messages'      => $rows->count() > 0 ? round($totalMessages / $rows->count(), 1) : 0,
            'median_messages'   => $lengths ? $lengths[intdiv(count($lengths), 2)] : 0,
            'max_messages'      => $lengths ? end($lengths) : 0,
            'duration_coverage' => $rows->count() > 0 ? round(100 * $withDuration / $rows->count(), 1) : 0,
            'entrants'          => $entrants,
            'activation_rate'   => $entrants > 0 ? round(100 * $rows->pluck('employee_id')->unique()->count() / $entrants, 1) : null,
            'by_bu'             => $rows->groupBy('business_unit')->map(fn ($g) => [
                'days'  => $g->count(),
                'users' => $g->pluck('employee_id')->unique()->count(),
            ])->all(),
            'conversations'     => $conversations,
        ];
    }

    /**
     * One page of Ruang conversations, with the question and answer inline.
     *
     * Paginated because the aggregate path decodes every row's message JSON in
     * PHP: fine at the current 77 rows, not fine later. Only the rows on the
     * requested page are decoded here.
     *
     * @return array<string, mixed>
     */
    public function ruangConversations(array $w, ?string $bu = null, ?string $status = null, int $perPage = 25, int $page = 1): array
    {
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);

        $base = fn () => DB::table('daily_chat_logs')
            ->whereBetween('date', [$w['from'], $w['to']])
            ->when($bu, fn ($q) => $q->where('business_unit', $bu))
            ->when($status === 'error', fn ($q) => $q->where('last_status', '!=', 'ok'))
            ->when($status === 'ok', fn ($q) => $q->where('last_status', 'ok'));

        // Jumlah baris dan jumlah baris bermasalah datang dari satu query.
        // Bentuk CASE ini sengaja meniru semantik `!=` di SQL: kalau suatu saat
        // last_status dibuat nullable, NULL akan membuat WHEN bernilai NULL —
        // bukan benar — sehingga baris tanpa status tidak ikut terhitung sebagai
        // bermasalah, sama seperti sebelum query ini digabung.
        $tally = (clone $base())
            ->selectRaw('COUNT(*) total')
            ->selectRaw('SUM(CASE WHEN last_status <> ? THEN 1 ELSE 0 END) errors', ['ok'])
            ->first();

        $total = (int) ($tally->total ?? 0);
        $errorRows = (int) ($tally->errors ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));

        // Clamp rather than serve an empty page: a stale ?page= from a bookmark
        // or a shrinking window should land on the last real page.
        $page = min($page, $lastPage);

        $rows = (clone $base())
            ->orderByDesc('date')->orderByDesc('updated_at')
            ->forPage($page, $perPage)
            ->get(['id', 'employee_id', 'employee_name', 'business_unit', 'phone', 'date', 'messages', 'last_status', 'error_count', 'updated_at']);

        $conversations = $rows->map(function ($r) {
            $msgs = json_decode($r->messages ?? '[]', true);
            $msgs = is_array($msgs) ? $msgs : [];

            $firstQuestion = null;
            $lastAnswer = null;
            $lastAt = null;

            foreach ($msgs as $m) {
                $sender = $m['sender'] ?? '';
                $text = (string) ($m['message'] ?? $m['text'] ?? '');

                if ($sender === 'User' && $firstQuestion === null) {
                    $firstQuestion = $text;
                }

                if ($sender !== 'User' && $text !== '') {
                    $lastAnswer = $text;
                }

                // 'timestamp' is only present on messages written after the
                // logging fix; older rows have 'time' (a clock time) only.
                $lastAt = $m['timestamp'] ?? $m['time'] ?? $lastAt;
            }

            return [
                'id'            => (int) $r->id,
                'date'          => $r->date,
                'employee_name' => $r->employee_name,
                'employee_id'   => $r->employee_id,
                'bu'            => $r->business_unit,
                'phone_masked'  => $this->maskPhone((string) $r->phone),
                'messages'      => count($msgs),
                'exchanges'     => intdiv(count($msgs), 2),
                'question'      => $firstQuestion === null ? null : Str::limit($firstQuestion, 180),
                'answer'        => $lastAnswer === null ? null : Str::limit($lastAnswer, 220),
                'last_at'       => $lastAt,
                'status'        => $r->last_status ?: 'ok',
                'error_count'   => (int) ($r->error_count ?? 0),
            ];
        })->all();

        return [
            'rows'      => $conversations,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
            'errors'    => $errorRows,
        ];
    }

    /** Full transcript for one Ruang day. Authorisation is the caller's job. */
    public function ruangTranscript(int $id): ?array
    {
        $row = DB::table('daily_chat_logs')->where('id', $id)->first();

        if (! $row) {
            return null;
        }

        $msgs = json_decode($row->messages ?? '[]', true);

        return [
            'id'            => $row->id,
            'date'          => $row->date,
            'employee_name' => $row->employee_name,
            'employee_id'   => $row->employee_id,
            'bu'            => $row->business_unit,
            'phone_masked'  => $this->maskPhone((string) $row->phone),
            'messages'      => is_array($msgs) ? $msgs : [],
        ];
    }

    // ------------------------------------------------------------------
    // 7. Unknown numbers
    // ------------------------------------------------------------------

    public function unknownNumbers(array $w, int $limit = 100): array
    {
        $base = DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->where('event_type', 'employee_lookup_failed');

        // Satu query bergrup, lalu jumlah nomor unik, total percobaan, dan
        // nomor yang mencoba berulang dihitung dari hasil yang sama, bukan
        // dengan tiga query tambahan. Satu baris per nomor, jadi ukurannya
        // mengikuti banyaknya nomor yang gagal — bukan banyaknya percobaan —
        // dan yang ditampilkan tetap dibatasi $limit teratas.
        $grouped = (clone $base)
            ->selectRaw('phone, COUNT(*) attempts, MIN(occurred_at) first_at, MAX(occurred_at) last_at')
            ->groupBy('phone')->orderByDesc('attempts')->orderByDesc('last_at')
            ->get();

        $rows = $grouped->take($limit);

        // Did the number ever get in later? One grouped query for all rows,
        // not one query per row.
        $phones = $rows->pluck('phone')->all();
        $lastFailure = $rows->pluck('last_at', 'phone');
        $resolved = [];

        if ($phones !== []) {
            $activity = DB::table('nastari_events')
                ->whereIn('phone', $phones)->where('source', 'wa_incoming')
                ->selectRaw('phone, MAX(occurred_at) last_msg, COUNT(*) msgs')
                ->groupBy('phone')->get()->keyBy('phone');

            foreach ($phones as $phone) {
                $seen = $activity->get($phone);
                $resolved[$phone] = $seen !== null
                    && $seen->last_msg > ($lastFailure[$phone] ?? '')
                    && $seen->msgs >= 2;
            }
        }

        return [
            // COUNT(DISTINCT phone) tidak menghitung NULL, jadi grup NULL
            // dikeluarkan di sini; total percobaan tetap mencakup semuanya,
            // persis seperti COUNT(*) sebelumnya.
            'unique_numbers' => $grouped->filter(fn ($r) => $r->phone !== null)->count(),
            'total_attempts' => (int) $grouped->sum('attempts'),
            'repeat_offenders' => $grouped->filter(fn ($r) => (int) $r->attempts > 1)->count(),
            'rows' => $rows->map(fn ($r) => [
                'phone_masked' => $this->maskPhone((string) $r->phone),
                'attempts'     => (int) $r->attempts,
                'first_at'     => $r->first_at,
                'last_at'      => $r->last_at,
                'status'       => ($resolved[$r->phone] ?? false) ? 'Akhirnya masuk' : 'Masih gagal',
            ])->all(),
            'daily' => (clone $base)->selectRaw('event_date, COUNT(*) attempts, COUNT(DISTINCT phone) numbers')
                ->groupBy('event_date')->orderBy('event_date')->get()
                ->map(fn ($r) => [
                    'date'    => $r->event_date,
                    'label'   => Carbon::parse($r->event_date)->format('d M'),
                    'attempts' => (int) $r->attempts,
                    'numbers' => (int) $r->numbers,
                ])->all(),
        ];
    }

    // ------------------------------------------------------------------
    // 8. Errors
    // ------------------------------------------------------------------

    public function errors(array $w): array
    {
        $base = DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->where('source', 'error');

        return [
            'total' => (clone $base)->count(),
            'by_kind' => (clone $base)->selectRaw('feature_label kind, COUNT(*) c')
                ->groupBy('feature_label')->orderByDesc('c')->get()
                ->map(fn ($r) => ['kind' => $r->kind ?: 'Error lain', 'count' => (int) $r->c])->all(),
            'recent' => (clone $base)->orderByDesc('occurred_at')->limit(20)
                ->get(['occurred_at', 'feature_label', 'payload'])
                ->map(function ($r) {
                    $p = json_decode($r->payload ?? '{}', true);

                    return [
                        'at'      => $r->occurred_at,
                        'kind'    => $r->feature_label ?: 'Error lain',
                        'message' => (string) ($p['message'] ?? ''),
                    ];
                })->all(),
        ];
    }

    // ------------------------------------------------------------------
    // 8b. System health — errors, external services, latency, sync
    // ------------------------------------------------------------------

    /**
     * Everything the Health tab shows.
     *
     * Attribution (service, endpoint, error_type, duration) only exists on rows
     * written live by NastariActivityLogger; rows recovered from the log files
     * predate structured logging and carry a coarse Indonesian label only. The
     * returned 'live_since' is what the UI uses to say so, rather than
     * presenting an incomplete breakdown as if it were complete.
     */
    public function health(array $w): array
    {
        return $this->once('health:' . $w['from'] . $w['to'], fn () => $this->computeHealth($w));
    }

    private function computeHealth(array $w): array
    {
        $errors = fn () => DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->where('source', 'error');

        $byType = (clone $errors())
            ->selectRaw('COALESCE(NULLIF(error_type, ""), "lain") kind, COUNT(*) c, MAX(occurred_at) last_at')
            ->groupBy('kind')->orderByDesc('c')->get()
            ->map(fn ($r) => [
                'kind'    => (string) $r->kind,
                'label'   => $this->errorTypeLabel((string) $r->kind),
                'count'   => (int) $r->c,
                'last_at' => $r->last_at,
            ])->all();

        // Setiap baris error masuk ke salah satu kelompok jenis, jadi totalnya
        // adalah jumlah kelompok — tidak perlu satu query COUNT(*) sendiri.
        $total = array_sum(array_column($byType, 'count'));

        $byService = (clone $errors())->whereNotNull('service')
            ->selectRaw('service, COUNT(*) c, MAX(occurred_at) last_at')
            ->groupBy('service')->orderByDesc('c')->get()
            ->map(fn ($r) => [
                'service' => (string) $r->service,
                'count'   => (int) $r->c,
                'last_at' => $r->last_at,
            ])->all();

        $byEndpoint = (clone $errors())->whereNotNull('endpoint')
            ->selectRaw('service, endpoint, COALESCE(NULLIF(error_type, ""), "lain") kind, COUNT(*) c, MAX(occurred_at) last_at, ROUND(AVG(duration_ms)) avg_ms, MAX(http_status) http_status')
            ->groupBy('service', 'endpoint', 'kind')
            ->orderByDesc('c')->limit(20)->get()
            ->map(fn ($r) => [
                'service'     => (string) $r->service,
                'endpoint'    => (string) $r->endpoint,
                'kind'        => (string) $r->kind,
                'label'       => $this->errorTypeLabel((string) $r->kind),
                'count'       => (int) $r->c,
                'avg_ms'      => $r->avg_ms === null ? null : (int) $r->avg_ms,
                'http_status' => $r->http_status === null ? null : (int) $r->http_status,
                'last_at'     => $r->last_at,
            ])->all();

        $daily = (clone $errors())
            ->selectRaw('event_date, COUNT(*) c')
            ->groupBy('event_date')->orderBy('event_date')->get()
            ->map(fn ($r) => [
                'date'  => $r->event_date,
                'label' => Carbon::parse($r->event_date)->format('d M'),
                'count' => (int) $r->c,
            ])->all();

        $recent = (clone $errors())->orderByDesc('occurred_at')->limit(25)
            ->get(['occurred_at', 'service', 'endpoint', 'error_type', 'http_status', 'duration_ms', 'feature_label', 'payload'])
            ->map(function ($r) {
                $p = json_decode($r->payload ?? '{}', true);

                return [
                    'at'          => $r->occurred_at,
                    'service'     => $r->service,
                    'endpoint'    => $r->endpoint,
                    'kind'        => $r->error_type ?: null,
                    'label'       => $r->error_type ? $this->errorTypeLabel((string) $r->error_type) : ($r->feature_label ?: 'Error lain'),
                    'http_status' => $r->http_status,
                    'duration_ms' => $r->duration_ms,
                    'message'     => Str::limit((string) ($p['message'] ?? $p['detail'] ?? $p['error'] ?? ''), 160),
                ];
            })->all();

        return [
            'total'       => $total,
            'by_type'     => $byType,
            'by_service'  => $byService,
            'by_endpoint' => $byEndpoint,
            'daily'       => $daily,
            'recent'      => $recent,
            'latency'     => $this->serviceLatency($w),
            'sync'        => $this->syncStatus(),
            'live_since'  => DB::table('nastari_events')->where('origin', 'live')->min('event_date'),
        ];
    }

    /**
     * Response time per external service.
     *
     * p95 is taken by offset rather than a percentile function, and is only
     * reported once there are enough samples for it to mean anything.
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceLatency(array $w): array
    {
        $rows = DB::table('nastari_events')
            ->whereBetween('event_date', [$w['from'], $w['to']])
            ->whereNotNull('service')
            ->whereNotNull('duration_ms')
            ->selectRaw('service, COUNT(*) calls, ROUND(AVG(duration_ms)) avg_ms, MAX(duration_ms) max_ms')
            ->selectRaw('SUM(source = "error") failures')
            ->groupBy('service')->orderByDesc('calls')->get();

        $slowMs = (int) config('nastari.logging.http.slow_ms', 5000);
        $out = [];

        foreach ($rows as $row) {
            $calls = (int) $row->calls;
            $p95 = null;

            if ($calls >= 20) {
                $p95 = DB::table('nastari_events')
                    ->whereBetween('event_date', [$w['from'], $w['to']])
                    ->where('service', $row->service)
                    ->whereNotNull('duration_ms')
                    ->orderBy('duration_ms')
                    ->offset((int) floor($calls * 0.95))
                    ->limit(1)
                    ->value('duration_ms');
            }

            $out[] = [
                'service'   => (string) $row->service,
                'calls'     => $calls,
                'avg_ms'    => (int) $row->avg_ms,
                'p95_ms'    => $p95 === null ? null : (int) $p95,
                'max_ms'    => (int) $row->max_ms,
                'failures'  => (int) $row->failures,
                'slow_over' => $slowMs,
            ];
        }

        return $out;
    }

    /**
     * Status sinkronisasi harian usernastari <- kpncorp.employees.
     *
     * Public because the dashboard reports it as a section of its own
     * (kebutuhan (b)), not only as part of the health roll-up.
     *
     * @return array<string, mixed>
     */
    public function syncStatus(): array
    {
        $runs = DB::table('employee_sync_runs')->orderByDesc('id')->limit(7)->get();
        $last = $runs->firstWhere('status', 'ok');

        return [
            'last_ok'      => $last?->finished_at,
            'last_counts'  => $last === null ? null : [
                'examined'          => (int) $last->examined,
                'updated'           => (int) $last->updated,
                'marked_active'     => (int) $last->marked_active,
                'marked_inactive'   => (int) $last->marked_inactive,
                'pre_registered'    => (int) $last->pre_registered,
                'not_found_in_hris' => (int) $last->not_found_in_hris,
                'ambiguous_phones'  => (int) $last->ambiguous_phones,
            ],
            'is_stale'     => $last === null || Carbon::parse($last->finished_at)->lt(Carbon::now()->subHours(36)),
            'recent'       => $runs->map(fn ($r) => [
                'started_at' => $r->started_at,
                'trigger'    => $r->trigger,
                'status'     => $r->status,
                'message'    => Str::limit((string) $r->message, 120),
            ])->all(),
        ];
    }

    private function errorTypeLabel(string $kind): string
    {
        return match ($kind) {
            'timeout'         => 'Timeout layanan eksternal',
            'connection'      => 'Kegagalan koneksi',
            'unavailable'     => 'Layanan eksternal tidak tersedia',
            'http_error'      => 'Respons error layanan eksternal',
            'slow'            => 'Layanan eksternal lambat',
            'generate_failed' => 'Gagal membuat surat',
            'ai_no_response'  => 'AI tidak merespons',
            'lain'            => 'Error lain',
            default           => $kind,
        };
    }

    // ------------------------------------------------------------------
    // 9. Insights — generated from the numbers above, never hardcoded
    // ------------------------------------------------------------------

    /**
     * Each insight separates observation from suggested action, and says which
     * metric it came from so an admin can verify it.
     *
     * @return array<int, array{severity:string, title:string, observation:string, action:string, basis:string}>
     */
    public function insights(array $w, ?string $bu = null): array
    {
        $out = [];
        $o = $this->overview($w, $bu);
        $bus = $this->businessUnits($w);
        $features = $this->featureRanking($w, $bu);
        $pandu = $this->pandu($w, $bu);
        $hours = $this->byHour($w, $bu);
        $unknown = $this->unknownNumbers($w, 1);
        $ruang = $this->ruang($w, $bu);

        // Pandu escalation backlog
        $esc = $pandu['escalations'];
        if ($esc['open'] > 0 && $esc['open_pct'] >= 40) {
            $out[] = [
                'severity'    => 'critical',
                'title'       => 'Tiket eskalasi Pandu menumpuk tanpa jawaban',
                'observation' => "{$esc['open']} dari {$esc['total']} tiket ({$esc['open_pct']}%) masih berstatus belum selesai."
                    . ($esc['oldest_age'] ? " Yang tertua sudah {$esc['oldest_age']} hari." : ''),
                'action'      => 'Rekomendasi investigasi: pastikan HCO penerima email eskalasi masih aktif dan tiket punya SLA serta pemilik yang jelas.',
                'basis'       => 'Tabel hears — status != Done',
            ];
        }

        // Unknown numbers
        if ($unknown['unique_numbers'] > 0) {
            $ratio = $unknown['unique_numbers'] > 0
                ? round($unknown['total_attempts'] / $unknown['unique_numbers'], 1) : 0;
            $out[] = [
                'severity'    => $unknown['unique_numbers'] >= 20 ? 'warning' : 'info',
                'title'       => 'Nomor tak dikenal mencoba mengakses Nastari',
                'observation' => "{$unknown['unique_numbers']} nomor unik gagal dikenali, dengan total {$unknown['total_attempts']} percobaan (rata-rata {$ratio} kali per nomor).",
                'action'      => 'Kemungkinan area investigasi: sinkronisasi nomor HP karyawan di Darwinbox, data karyawan kedaluwarsa, atau proses onboarding.',
                'basis'       => 'nastari_events — employee_lookup_failed (COUNT DISTINCT vs COUNT)',
            ];
        }

        // BU adoption spread
        $withRate = array_values(array_filter($bus, fn ($b) => $b['registration_rate'] !== null && $b['employees'] >= 100));
        if (count($withRate) >= 2) {
            usort($withRate, fn ($a, $b) => $a['registration_rate'] <=> $b['registration_rate']);
            $low = $withRate[0];
            $high = end($withRate);
            if ($high['registration_rate'] > 0 && $low['registration_rate'] < $high['registration_rate'] * 0.6) {
                $out[] = [
                    'severity'    => 'warning',
                    'title'       => "Adopsi di {$low['bu']} jauh di bawah {$high['bu']}",
                    'observation' => "{$low['bu']}: {$low['registration_rate']}% karyawan terdaftar ({$low['registered']} dari {$low['employees']}). "
                        . "{$high['bu']}: {$high['registration_rate']}% ({$high['registered']} dari {$high['employees']}).",
                    'action'      => 'Peluang: sosialisasi dan onboarding terarah di unit dengan adopsi rendah.',
                    'basis'       => 'usernastari vs kpncorp.employees (deleted_at IS NULL)',
                ];
            }
        }

        // Top feature
        if ($features !== []) {
            $top = $features[0];
            $out[] = [
                'severity'    => 'info',
                'title'       => "{$top['label']} adalah fitur yang paling sering dipakai",
                'observation' => "{$top['uses']} pemakaian oleh {$top['users']} user — {$top['share']}% dari seluruh aksi.",
                'action'      => 'Peluang: prioritaskan keandalan dan kecepatan fitur ini karena dampaknya paling luas.',
                'basis'       => 'nastari_events — feature_selected',
            ];

            $rare = array_values(array_filter($features, fn ($f) => $f['users'] <= 2));
            if (count($rare) >= 2) {
                $names = implode(', ', array_map(fn ($f) => $f['label'], array_slice($rare, 0, 4)));
                $out[] = [
                    'severity'    => 'info',
                    'title'       => 'Beberapa fitur hampir tidak tersentuh',
                    'observation' => "Pemakaian sangat rendah pada: {$names}.",
                    'action'      => 'Pemakaian rendah — layak ditelusuri. Belum tentu fiturnya tidak berguna: bisa jadi sulit ditemukan di menu.',
                    'basis'       => 'nastari_events — feature_selected, <= 2 user unik',
                ];
            }
        }

        // Navigation friction
        if ($o['nav_per_action'] !== null && $o['nav_per_action'] >= 0.6) {
            $out[] = [
                'severity'    => 'warning',
                'title'       => 'Rasio navigasi terhadap aksi cukup tinggi',
                'observation' => "Setiap 1 aksi bernilai diiringi {$o['nav_per_action']} langkah navigasi ({$o['navigation']} navigasi vs {$o['actions']} aksi).",
                'action'      => 'Rekomendasi investigasi: struktur menu mungkin membuat user harus bolak-balik untuk menemukan yang dicari.',
                'basis'       => 'nastari_events — menu_opened + navigation + session_end vs aksi',
            ];
        }

        // Peak hour, computed not hardcoded
        $peak = collect($hours)->sortByDesc('messages')->first();
        if ($peak && $peak['messages'] > 0) {
            $next = sprintf('%02d:00', ($peak['hour'] + 1) % 24);
            $out[] = [
                'severity'    => 'info',
                'title'       => "Puncak pemakaian pada {$peak['label']}–{$next}",
                'observation' => "{$peak['messages']} pesan dari {$peak['users']} user unik pada jam tersebut (zona waktu " . config('app.timezone') . ').',
                'action'      => 'Peluang: jadwalkan pemeliharaan dan broadcast di luar jam puncak.',
                'basis'       => 'nastari_events — event_hour',
            ];
        }

        // Pandu failure concentration
        $panduFail = $pandu['not_found'] + $pandu['no_response'] + $pandu['error'];
        if ($pandu['total'] >= 5 && $panduFail > 0) {
            $failPct = round(100 * $panduFail / $pandu['total'], 1);
            $out[] = [
                'severity'    => $failPct >= 30 ? 'warning' : 'info',
                'title'       => 'Sebagian pertanyaan Pandu tidak terjawab',
                'observation' => "{$panduFail} dari {$pandu['total']} pertanyaan ({$failPct}%) tidak menghasilkan jawaban: "
                    . "{$pandu['not_found']} tidak ada di knowledge base, {$pandu['no_response']} tanpa respons, {$pandu['error']} error layanan.",
                'action'      => 'Peluang: lengkapi knowledge base untuk topik yang gagal, dan pastikan timeout layanan tetap menawarkan eskalasi.',
                'basis'       => 'nastari_events — pandu_question.outcome',
            ];
        }

        // Repeated Pandu questions
        if (($pandu['repeated'][0]['count'] ?? 0) >= 2) {
            $r = $pandu['repeated'][0];
            $out[] = [
                'severity'    => 'info',
                'title'       => 'Ada pertanyaan yang berulang kali ditanyakan',
                'observation' => "\"" . Str::limit($r['question'], 90) . "\" muncul {$r['count']} kali.",
                'action'      => 'Belum tentu satu sebab: bisa jawaban kurang jelas, knowledge base kurang, UX kurang jelas, atau fitur belum ada. Layak ditelusuri.',
                'basis'       => 'nastari_events — pandu_question, dikelompokkan dari teks yang dinormalisasi',
            ];
        }

        // Ruang activation
        if ($ruang['activation_rate'] !== null && $ruang['entrants'] >= 20 && $ruang['activation_rate'] < 60) {
            $out[] = [
                'severity'    => 'warning',
                'title'       => 'Banyak yang masuk Ruang tapi tidak bercerita',
                'observation' => "{$ruang['entrants']} user membuka Ruang, hanya {$ruang['unique_users']} yang mengirim pesan ({$ruang['activation_rate']}%).",
                'action'      => 'Rekomendasi investigasi: kalimat pembuka Ruang mungkin belum mendorong orang untuk mulai bercerita.',
                'basis'       => 'nastari_events (deeplink_ruang/ruang) vs daily_chat_logs',
            ];
        }

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $out;
    }

    // ------------------------------------------------------------------
    // 10. Data gaps — kept honest and in one place
    // ------------------------------------------------------------------

    public function dataGaps(): array
    {
        $bounds = $this->dataBounds();

        return [
            'available' => [
                ['metric' => 'Headcount & business unit', 'source' => 'kpncorp.employees (deleted_at IS NULL)', 'note' => 'Denominator adopsi yang bisa dipercaya'],
                ['metric' => 'User terdaftar & tanggal daftar', 'source' => 'usernastari.created_at', 'note' => 'created_at bertahan meski profil di-sync ulang'],
                ['metric' => 'Interaksi, fitur, jam, hari, nomor gagal', 'source' => 'nastari_events', 'note' => 'Hasil ingest log; permanen sejak ' . ($bounds['min'] ?? '-')],
                ['metric' => 'Surat selesai dibuat', 'source' => 'letter_logs', 'note' => 'Satu-satunya sinyal penyelesaian fitur yang asli'],
                ['metric' => 'Transkrip Ruang (kedua sisi)', 'source' => 'daily_chat_logs.messages', 'note' => 'Menyimpan pesan user dan balasan AI'],
                ['metric' => 'Pertanyaan Pandu + latensi', 'source' => 'nastari_events (pandu_question)', 'note' => 'Termasuk outcome dan waktu respons RAG'],
            ],
            'derivable' => [
                ['metric' => 'Adopsi per BU (terdaftar & aktif)', 'how' => 'usernastari / employees, dan COUNT DISTINCT phone dari events per BU'],
                ['metric' => 'User baru vs kembali', 'how' => 'MIN(event_date) per nomor, dibandingkan dengan rentang terpilih'],
                ['metric' => 'Funnel surat', 'how' => 'menu_opened → feature_selected → baris letter_logs'],
                ['metric' => 'Rasio navigasi per aksi', 'how' => 'menu_opened + navigation dibagi jumlah aksi'],
                ['metric' => 'Pertanyaan Pandu berulang', 'how' => 'Normalisasi teks lalu dikelompokkan'],
            ],
            'cannot' => [
                ['metric' => 'Riwayat interaksi sebelum ' . ($bounds['min'] ?? 'ingest pertama'), 'why' => 'Log dirotasi setiap 14 hari. Data sebelum ingest pertama sudah hilang permanen dan tidak bisa dipulihkan.'],
                ['metric' => 'Sukses/gagal per fitur', 'why' => 'Bot menjawab langsung tanpa mencatat hasil. Hanya fitur surat yang punya sinyal penyelesaian.'],
                ['metric' => 'Isi balasan bot di luar Ruang', 'why' => 'Tidak ada yang menyimpan pesan keluar.'],
                ['metric' => 'Waktu respons bot secara umum', 'why' => 'Hanya Pandu yang punya dua timestamp untuk dihitung selisihnya.'],
                ['metric' => 'Pertanyaan Pandu yang berhasil dijawab, sebagai data permanen', 'why' => 'Tabel hears hanya menyimpan eskalasi. Pertanyaan yang terjawab hanya ada di log — jadi hanya bertahan lewat ingest ini.'],
                ['metric' => 'Confidence score jawaban Pandu', 'why' => 'Layanan RAG tidak mengembalikan skor.'],
                ['metric' => 'Durasi percakapan Ruang yang menyeluruh', 'why' => 'Hanya sebagian baris punya timestamp awal dan akhir yang berbeda.'],
                ['metric' => 'Pemakaian panel admin', 'why' => 'Tidak ada request admin yang dicatat sama sekali.'],
            ],
            'recommended' => [
                ['event' => 'pandu_answer', 'why' => 'Simpan setiap tanya-jawab Pandu ke DB, bukan hanya eskalasi. Tanpa ini, satu-satunya jejak jawaban sukses adalah log yang dirotasi.', 'effort' => 'Kecil — tambahan di HearService'],
                ['event' => 'feature_completed / feature_failed', 'why' => 'Membuka funnel nyata untuk semua fitur, bukan hanya surat.', 'effort' => 'Sedang — perlu sentuh alur WhatsApp'],
                ['event' => 'response_time', 'why' => 'Mengukur pengalaman user, bukan hanya volume.', 'effort' => 'Sedang'],
                ['event' => 'admin_request', 'why' => 'Membuat pemakaian panel admin bisa diukur.', 'effort' => 'Kecil — satu middleware'],
                ['event' => 'LOG_DAILY_DAYS dinaikkan', 'why' => 'Ingest berjalan tiap 10 menit, tapi retensi 14 hari tetap jadi batas pemulihan kalau ingest mati.', 'effort' => 'Konfigurasi'],
            ],
        ];
    }

    // ------------------------------------------------------------------

    public function availableBusinessUnits(): array
    {
        return Cache::remember('nastari_bu_list', self::CACHE_TTL, function () {
            $fromEvents = DB::table('nastari_events')->whereNotNull('business_unit')
                ->distinct()->pluck('business_unit')->all();
            $fromUsers = DB::table('usernastari')->whereNotNull('group_company')
                ->where('group_company', '!=', '')->distinct()->pluck('group_company')->all();

            // employees is the authoritative list, and it carries units that
            // nobody has registered from yet (KPN Sugar, for instance, only
            // appears on inactive rows). Never hardcode this.
            $fromHris = [];

            try {
                $fromHris = DB::connection('kpncorp')->table('employees')
                    ->whereNotNull('group_company')->where('group_company', '!=', '')
                    ->distinct()->pluck('group_company')->all();
            } catch (\Throwable) {
                $fromHris = [];
            }

            $all = array_values(array_unique(array_merge($fromEvents, $fromUsers, $fromHris)));
            sort($all);

            return $all;
        });
    }

    // ------------------------------------------------------------------
    // 5b. Pandu — ringkasan, tren, tanya-jawab
    // ------------------------------------------------------------------

    /**
     * Angka utama Pandu, dibaca dari pandu_qa.
     *
     * Sengaja tidak dibaca dari `hears`: tabel itu hanya menerima eskalasi,
     * jadi mengukur Pandu dari sana berarti mengukur kegagalannya saja dan
     * mengabaikan setiap pertanyaan yang berhasil dijawab.
     *
     * @return array<string, mixed>
     */
    public function panduOverview(array $w, ?string $bu = null): array
    {
        $agg = DB::table('pandu_qa')
            ->whereBetween('ask_date', [$w['from'], $w['to']])
            ->when($bu, fn ($q) => $q->where('business_unit', $bu))
            ->selectRaw('COUNT(*) total, COUNT(DISTINCT phone) users')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) answered', ['answered'])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) not_found', ['not_found'])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) no_response', ['no_response'])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) errors', ['error'])
            ->selectRaw('SUM(CASE WHEN ticket_code IS NOT NULL THEN 1 ELSE 0 END) escalated')
            ->selectRaw('SUM(CASE WHEN answer IS NULL THEN 1 ELSE 0 END) answer_missing')
            ->selectRaw('SUM(CASE WHEN handoff_from IS NOT NULL THEN 1 ELSE 0 END) from_ruang')
            ->selectRaw('ROUND(AVG(CASE WHEN outcome = ? THEN duration_ms END)) avg_ms', ['answered'])
            ->first();

        $total = (int) ($agg->total ?? 0);
        $answered = (int) ($agg->answered ?? 0);

        // Antrean eskalasi tetap dibaca dari tabel operasionalnya, karena di
        // sanalah jawaban HCO berada.
        $tickets = DB::table('hears')->whereNull('deleted_at')
            ->selectRaw('COUNT(*) total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) done', ['Done'])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) open', ['Done'])
            ->selectRaw('MIN(CASE WHEN status <> ? THEN date END) oldest_open', ['Done'])
            ->first();

        return [
            'total'          => $total,
            'users'          => (int) ($agg->users ?? 0),
            'answered'       => $answered,
            'not_found'      => (int) ($agg->not_found ?? 0),
            'no_response'    => (int) ($agg->no_response ?? 0),
            'errors'         => (int) ($agg->errors ?? 0),
            'escalated'      => (int) ($agg->escalated ?? 0),
            'from_ruang'     => (int) ($agg->from_ruang ?? 0),
            'answer_missing' => (int) ($agg->answer_missing ?? 0),
            'answer_rate'    => $total > 0 ? round(100 * $answered / $total, 1) : null,
            'avg_seconds'    => $agg->avg_ms === null ? null : round(((int) $agg->avg_ms) / 1000, 1),
            'tickets'        => [
                'total'       => (int) ($tickets->total ?? 0),
                'open'        => (int) ($tickets->open ?? 0),
                'done'        => (int) ($tickets->done ?? 0),
                'oldest_open' => $tickets->oldest_open ?? null,
            ],
        ];
    }

    /**
     * Deret harian untuk grafik Pandu: dijawab vs tidak ditemukan vs gagal.
     *
     * Hari tanpa pertanyaan tetap muncul sebagai nol, supaya jeda pemakaian
     * terlihat sebagai jeda dan bukan sebagai garis yang menyambung mulus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panduDaily(array $w, ?string $bu = null): array
    {
        $rows = DB::table('pandu_qa')
            ->whereBetween('ask_date', [$w['from'], $w['to']])
            ->when($bu, fn ($q) => $q->where('business_unit', $bu))
            ->selectRaw('ask_date, COUNT(*) total')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) answered', ['answered'])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) not_found', ['not_found'])
            ->selectRaw('SUM(CASE WHEN outcome IN (?, ?) THEN 1 ELSE 0 END) failed', ['error', 'no_response'])
            ->groupBy('ask_date')->get()->keyBy('ask_date');

        $out = [];
        $cursor = Carbon::parse($w['from']);
        $end = Carbon::parse($w['to']);

        while ($cursor <= $end) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);

            $out[] = [
                'date'      => $date,
                'label'     => $cursor->format('d M'),
                'total'     => (int) ($row->total ?? 0),
                'answered'  => (int) ($row->answered ?? 0),
                'not_found' => (int) ($row->not_found ?? 0),
                'failed'    => (int) ($row->failed ?? 0),
            ];

            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Pertanyaan yang berulang, dikelompokkan pada bentuk teks yang dinormalkan.
     *
     * Pengelompokannya dilakukan di PHP, bukan SQL, karena normalisasinya
     * membuang angka dan tanda baca — dan ini hanya berjalan atas sejumlah
     * baris terakhir yang dibatasi, bukan seluruh tabel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panduTopQuestions(array $w, ?string $bu = null, int $limit = 10): array
    {
        $rows = DB::table('pandu_qa')
            ->whereBetween('ask_date', [$w['from'], $w['to']])
            ->when($bu, fn ($q) => $q->where('business_unit', $bu))
            ->orderByDesc('asked_at')->limit(300)
            ->get(['question', 'outcome', 'asked_at']);

        $groups = [];

        foreach ($rows as $row) {
            $key = $this->normaliseQuestion((string) $row->question);

            if ($key === '') {
                continue;
            }

            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
            $groups[$key]['sample'] = $groups[$key]['sample'] ?? (string) $row->question;
            $groups[$key]['last_at'] = $groups[$key]['last_at'] ?? $row->asked_at;
            $groups[$key]['outcomes'][$row->outcome] = ($groups[$key]['outcomes'][$row->outcome] ?? 0) + 1;
        }

        return collect($groups)
            ->filter(fn ($g) => $g['count'] > 1)
            ->sortByDesc('count')
            ->take($limit)
            ->map(fn ($g) => [
                'question'  => Str::limit($g['sample'], 140),
                'count'     => $g['count'],
                'answered'  => $g['outcomes']['answered'] ?? 0,
                'not_found' => ($g['outcomes']['not_found'] ?? 0) + ($g['outcomes']['no_response'] ?? 0),
                'last_at'   => $g['last_at'],
            ])->values()->all();
    }

    /**
     * Satu halaman tanya-jawab Pandu, terbaru lebih dulu.
     *
     * Kalau pertanyaannya berujung tiket, jawaban HCO dari `hears` ikut
     * diambil — satu query untuk seluruh halaman, bukan satu per baris —
     * sehingga sebuah pertanyaan yang tidak terjawab bisa ditelusuri sampai
     * jawaban akhirnya.
     *
     * @return array<string, mixed>
     */
    public function panduConversations(
        array $w,
        ?string $bu = null,
        ?string $outcome = null,
        ?string $search = null,
        int $perPage = 15,
        int $page = 1
    ): array {
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);

        $base = fn () => DB::table('pandu_qa')
            ->whereBetween('ask_date', [$w['from'], $w['to']])
            ->when($bu, fn ($q) => $q->where('business_unit', $bu))
            ->when($outcome === 'answered', fn ($q) => $q->where('outcome', 'answered'))
            ->when($outcome === 'unanswered', fn ($q) => $q->whereIn('outcome', ['not_found', 'no_response', 'error']))
            ->when($outcome === 'escalated', fn ($q) => $q->whereNotNull('ticket_code'))
            ->when(
                $search !== null && trim($search) !== '',
                fn ($q) => $q->where(function ($inner) use ($search) {
                    $term = '%' . trim($search) . '%';
                    $inner->where('question', 'LIKE', $term)->orWhere('answer', 'LIKE', $term);
                })
            );

        $total = (clone $base())->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = (clone $base())
            ->orderByDesc('asked_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $ticketCodes = $rows->pluck('ticket_code')->filter()->unique()->all();

        $tickets = $ticketCodes === [] ? collect() : DB::table('hears')
            ->whereIn('ticket_code', $ticketCodes)
            ->get(['ticket_code', 'answer', 'status', 'pic'])
            ->keyBy('ticket_code');

        return [
            'rows' => $rows->map(function ($r) use ($tickets) {
                $ticket = $r->ticket_code === null ? null : $tickets->get($r->ticket_code);

                return [
                    'id'            => (int) $r->id,
                    'at'            => $r->asked_at,
                    'employee_name' => $r->employee_name,
                    'employee_id'   => $r->employee_id,
                    'bu'            => $r->business_unit,
                    'phone_masked'  => $this->maskPhone((string) $r->phone),
                    'question'      => (string) $r->question,
                    'answer'        => $r->answer,
                    'outcome'       => (string) $r->outcome,
                    'seconds'       => $r->duration_ms === null ? null : round(((int) $r->duration_ms) / 1000, 1),
                    'from_ruang'    => $r->handoff_from === 'ruang',
                    'origin'        => (string) $r->origin,
                    'ticket_code'   => $r->ticket_code,
                    'ticket_answer' => $ticket->answer ?? null,
                    'ticket_status' => $ticket->status ?? null,
                    'ticket_pic'    => $ticket->pic ?? null,
                ];
            })->all(),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
            'outcome'   => $outcome,
            'search'    => $search === null ? null : trim($search),
        ];
    }

    /**
     * Tiket eskalasi yang masih terbuka, tertua lebih dulu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panduOpenTickets(int $limit = 10): array
    {
        return DB::table('hears')->whereNull('deleted_at')
            ->where('status', '!=', 'Done')
            ->orderBy('date')->limit($limit)
            ->get(['ticket_code', 'date', 'question', 'pic', 'status', 'name'])
            ->map(fn ($t) => [
                'code'     => $t->ticket_code,
                'date'     => $t->date,
                'age_days' => (int) Carbon::parse($t->date)->diffInDays(Carbon::now()),
                'question' => Str::limit((string) $t->question, 110),
                'pic'      => $t->pic ?: 'Belum ditugaskan',
                'status'   => $t->status,
                'name'     => $t->name,
            ])->all();
    }

    // ------------------------------------------------------------------
    // 8c. Log aplikasi (storage/logs) — diagnostik, bukan metrik
    // ------------------------------------------------------------------

    /**
     * Ringkasan entri log dari nastari_log_entries.
     *
     * Sengaja terpisah dari health(): baris ini berasal dari file log, dan
     * sebagian error sudah punya baris terstrukturnya sendiri. Dashboard
     * menampilkan keduanya sebagai blok berbeda dan tidak pernah
     * menjumlahkannya — kalau digabung, satu kejadian bisa terhitung dua kali.
     *
     * @return array<string, mixed>
     */
    public function logSummary(array $w): array
    {
        $base = fn () => DB::table('nastari_log_entries')
            ->whereBetween('entry_date', [$w['from'], $w['to']]);

        $byLevel = (clone $base())
            ->selectRaw('level, COUNT(*) c, MAX(logged_at) last_at')
            ->groupBy('level')->orderByDesc('c')->get()
            ->map(fn ($r) => [
                'level'   => (string) $r->level,
                'count'   => (int) $r->c,
                'last_at' => $r->last_at,
            ])->all();

        // Satu baris per pesan yang sudah dinormalkan: seribu kejadian yang
        // sama menjadi satu baris dengan jumlah kemunculannya.
        $top = (clone $base())
            ->selectRaw('signature, MAX(title) title, MAX(kind) kind, MAX(level) level')
            ->selectRaw('MAX(file) file, MAX(line) line, COUNT(*) c, MIN(logged_at) first_at, MAX(logged_at) last_at')
            ->groupBy('signature')
            ->orderByDesc('c')->orderByDesc('last_at')
            ->limit(8)->get()
            ->map(fn ($r) => [
                'signature' => (string) $r->signature,
                'title'     => (string) $r->title,
                'kind'      => (string) ($r->kind ?: 'Error lain'),
                'level'     => (string) $r->level,
                'file'      => $r->file,
                'line'      => $r->line === null ? null : (int) $r->line,
                'count'     => (int) $r->c,
                'first_at'  => $r->first_at,
                'last_at'   => $r->last_at,
            ])->all();

        $scan = DB::table('nastari_log_scans')
            ->selectRaw('COUNT(*) files, MAX(last_scanned_at) last_at, SUM(entries_seen) seen')
            ->first();

        return [
            'total'      => array_sum(array_column($byLevel, 'count')),
            'by_level'   => $byLevel,
            'top'        => $top,
            'files'      => (int) ($scan->files ?? 0),
            'last_scan'  => $scan->last_at ?? null,
            'seen_total' => (int) ($scan->seen ?? 0),
            'retention'  => (int) config('nastari.logging.files.retention_days', 30),
        ];
    }

    /**
     * Satu halaman entri log, terbaru lebih dulu.
     *
     * Dipaginasi karena satu insiden bisa menghasilkan ratusan baris identik
     * dalam beberapa menit; tabelnya harus tetap bisa dibuka saat itu terjadi.
     *
     * @return array<string, mixed>
     */
    public function logEntries(array $w, ?string $level = null, ?string $signature = null, int $perPage = 15, int $page = 1): array
    {
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);

        $base = fn () => DB::table('nastari_log_entries')
            ->whereBetween('entry_date', [$w['from'], $w['to']])
            ->when($level, fn ($q) => $q->where('level', strtoupper($level)))
            ->when($signature, fn ($q) => $q->where('signature', $signature));

        $total = (clone $base())->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = (clone $base())
            ->orderByDesc('logged_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get(['logged_at', 'level', 'channel', 'kind', 'title', 'message', 'exception', 'file', 'line', 'source_file']);

        return [
            'rows' => $rows->map(fn ($r) => [
                'at'          => $r->logged_at,
                'level'       => (string) $r->level,
                'channel'     => $r->channel,
                'kind'        => (string) ($r->kind ?: 'Error lain'),
                'message'     => (string) $r->message,
                'exception'   => $r->exception,
                'file'        => $r->file,
                'line'        => $r->line === null ? null : (int) $r->line,
                'source_file' => (string) $r->source_file,
            ])->all(),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
            'level'     => $level,
        ];
    }

    // ------------------------------------------------------------------
    // 10. Akses fitur per karyawan
    // ------------------------------------------------------------------

    /**
     * Satu baris per karyawan yang memakai fitur di periode ini.
     *
     * Dipaginasi dan bisa dicari, karena ini data per orang di atas tabel yang
     * tumbuh terus: menampilkan semuanya sekaligus akan mengulang masalah yang
     * sama seperti tabel Ruang sebelum dipaginasi.
     *
     * `employee_status` hanya ada pada baris origin = 'live'; baris hasil parse
     * log tidak punya kolom itu, jadi statusnya diambil dari usernastari
     * sebagai sumber kebenaran, bukan dari event.
     *
     * @param  array<int, string>  $onlyBus  Batasan unit bisnis dari role scope.
     *                                       Kosong berarti tanpa batasan.
     * @return array<string, mixed>
     */
    public function employeeFeatureUsage(
        array $w,
        ?string $bu = null,
        ?string $search = null,
        int $perPage = 25,
        int $page = 1,
        array $onlyBus = []
    ): array {
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);

        // Pencarian nama diselesaikan lebih dulu di usernastari, supaya query
        // agregat di nastari_events tidak perlu join sama sekali.
        $searchIds = null;

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $searchIds = DB::table('usernastari')
                ->where(function ($q) use ($term) {
                    $q->where('full_name', 'LIKE', '%' . $term . '%')
                        ->orWhere('employee_id', 'LIKE', '%' . $term . '%');
                })
                ->limit(1000)
                ->pluck('employee_id')
                ->all();

            if ($searchIds === []) {
                return [
                    'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage,
                    'last_page' => 1, 'total_actions' => 0, 'search' => $term,
                ];
            }
        }

        $base = function () use ($w, $bu, $onlyBus, $searchIds) {
            $q = DB::table('nastari_events')
                ->whereBetween('event_date', [$w['from'], $w['to']])
                ->whereNotNull('employee_id')
                ->whereIn('event_type', self::ACTION_TYPES);

            if ($bu !== null && $bu !== '') {
                $q->where('business_unit', $bu);
            }

            if ($onlyBus !== []) {
                $q->whereIn('business_unit', $onlyBus);
            }

            if ($searchIds !== null) {
                $q->whereIn('employee_id', $searchIds);
            }

            return $q;
        };

        $total = (clone $base())->distinct()->count('employee_id');
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = (clone $base())
            ->selectRaw('employee_id, MAX(business_unit) bu, COUNT(*) actions')
            ->selectRaw('COUNT(DISTINCT feature_key) features, MIN(occurred_at) first_at, MAX(occurred_at) last_at')
            ->groupBy('employee_id')
            ->orderByDesc('actions')
            ->orderByDesc('last_at')
            ->forPage($page, $perPage)
            ->get();

        $ids = $rows->pluck('employee_id')->all();

        $meta = $ids === [] ? collect() : DB::table('usernastari')
            ->whereIn('employee_id', $ids)
            ->get(['employee_id', 'full_name', 'group_company', 'employment_status', 'registration_status', 'whatsapp_number'])
            ->keyBy('employee_id');

        // Fitur favorit: satu query bergrup untuk seluruh halaman, bukan satu
        // query per karyawan.
        $topFeatures = [];

        if ($ids !== []) {
            $grouped = (clone $base())
                ->whereIn('employee_id', $ids)
                ->whereNotNull('feature_key')
                ->whereNotIn('feature_key', self::ENTRY_FEATURES)
                ->selectRaw('employee_id, feature_key, MAX(feature_label) label, COUNT(*) c')
                ->groupBy('employee_id', 'feature_key')
                ->get();

            foreach ($grouped as $row) {
                $current = $topFeatures[$row->employee_id] ?? null;

                if ($current === null || (int) $row->c > $current['uses']) {
                    $topFeatures[$row->employee_id] = [
                        'label' => $row->label ?: $row->feature_key,
                        'uses'  => (int) $row->c,
                    ];
                }
            }
        }

        return [
            'rows' => $rows->map(function ($r) use ($meta, $topFeatures) {
                $m = $meta->get($r->employee_id);
                $top = $topFeatures[$r->employee_id] ?? null;

                return [
                    'employee_id'   => (string) $r->employee_id,
                    'name'          => $m->full_name ?? '—',
                    'bu'            => $m->group_company ?? $r->bu,
                    'status'        => $m->employment_status ?? 'unknown',
                    'registered'    => ($m->registration_status ?? null) === 'registered',
                    'phone_masked'  => $this->maskPhone((string) ($m->whatsapp_number ?? '')),
                    'actions'       => (int) $r->actions,
                    'features'      => (int) $r->features,
                    'top_feature'   => $top['label'] ?? null,
                    'top_uses'      => $top['uses'] ?? null,
                    'first_at'      => $r->first_at,
                    'last_at'       => $r->last_at,
                ];
            })->all(),
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $perPage,
            'last_page'     => $lastPage,
            'total_actions' => (int) (clone $base())->count(),
            'search'        => $search === null ? null : trim($search),
        ];
    }

    /**
     * Penolakan akses karyawan nonaktif.
     *
     * Ini yang membuktikan aturan aktif/nonaktif benar-benar berjalan di
     * backend, dan sekaligus alat deteksi false positive: kalau status seorang
     * karyawan salah, penolakannya muncul di sini pada hari yang sama.
     *
     * @return array<string, mixed>
     */
    public function accessDenials(array $w, ?string $bu = null): array
    {
        $base = fn () => (clone $this->events($w, $bu))->where('event_type', 'access_denied');

        $tally = (clone $base())
            ->selectRaw('COUNT(*) total, COUNT(DISTINCT employee_id) employees')
            ->first();

        return [
            'total'     => (int) ($tally->total ?? 0),
            'employees' => (int) ($tally->employees ?? 0),
            'by_feature' => (clone $base())
                ->selectRaw('COALESCE(NULLIF(feature_label, ""), feature_key) label, COUNT(*) c')
                ->groupBy('label')->orderByDesc('c')->limit(10)->get()
                ->map(fn ($r) => ['label' => (string) ($r->label ?: 'Tidak dikenal'), 'count' => (int) $r->c])->all(),
            'recent' => (clone $base())->orderByDesc('occurred_at')->limit(15)
                ->get(['occurred_at', 'employee_id', 'phone', 'feature_key', 'feature_label', 'business_unit'])
                ->map(fn ($r) => [
                    'at'           => $r->occurred_at,
                    'employee_id'  => $r->employee_id,
                    'phone_masked' => $this->maskPhone((string) $r->phone),
                    'feature'      => (string) ($r->feature_label ?: $r->feature_key ?: 'Tidak dikenal'),
                    'bu'           => $r->business_unit,
                ])->all(),
        ];
    }

    /**
     * Jam tersibuk dari deret per jam, untuk kalimat ringkasan di dashboard.
     *
     * @param  array<int, array<string, mixed>>  $hours  Hasil byHour().
     * @return array<string, mixed>|null
     */
    public function peakHour(array $hours): ?array
    {
        $peak = null;

        foreach ($hours as $row) {
            if ($peak === null || $row['messages'] > $peak['messages']) {
                $peak = $row;
            }
        }

        if ($peak === null || $peak['messages'] === 0) {
            return null;
        }

        $total = array_sum(array_column($hours, 'messages')) ?: 1;

        return [
            'label'    => $peak['label'],
            'hour'     => $peak['hour'],
            'messages' => $peak['messages'],
            'users'    => $peak['users'],
            'share'    => round(100 * $peak['messages'] / $total, 1),
        ];
    }

    public function maskPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        $len = strlen($digits);

        if ($len === 0) {
            return '—';
        }

        if ($len <= 8) {
            return substr($digits, 0, 2) . str_repeat('*', $len - 2);
        }

        return substr($digits, 0, 4) . str_repeat('*', $len - 8) . substr($digits, -4);
    }
}
