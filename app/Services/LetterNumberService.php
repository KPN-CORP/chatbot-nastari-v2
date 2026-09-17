<?php

namespace App\Services;

use App\Models\LetterLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues letter numbers such as "014/SK/PLT/09-2026".
 *
 * Replaces `LetterLog::count() + 1`, which handed out duplicate numbers to
 * concurrent requests and re-used numbers after any letter_logs row was
 * deleted. The counter lives in its own table and is incremented inside a
 * transaction with a row lock, so a number is never issued twice.
 */
class LetterNumberService
{
    /**
     * @param  string  $kind  Letter-number segment, e.g. 'SK' or 'SK-VISA'.
     * @param  string|null  $businessUnit  employees.group_company value.
     */
    public function next(string $kind, ?string $businessUnit): string
    {
        $code = $this->buCode($businessUnit);
        $period = date('m-Y');
        $scope = "{$kind}/{$code}/{$period}";

        $number = $this->increment($scope);

        return sprintf('%03d', $number) . "/{$kind}/{$code}/{$period}";
    }

    /**
     * The sequence is seeded from letter_logs the first time a scope is used,
     * so numbering continues from the existing letters instead of restarting
     * at 001 and colliding with them.
     */
    private function increment(string $scope): int
    {
        try {
            return DB::transaction(function () use ($scope) {
                $row = DB::table('letter_number_sequences')
                    ->where('scope', $scope)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    $seed = $this->seedFor($scope);

                    DB::table('letter_number_sequences')->insert([
                        'scope'       => $scope,
                        'last_number' => $seed + 1,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);

                    return $seed + 1;
                }

                $next = (int) $row->last_number + 1;

                DB::table('letter_number_sequences')
                    ->where('id', $row->id)
                    ->update(['last_number' => $next, 'updated_at' => now()]);

                return $next;
            }, 3);
        } catch (\Throwable $e) {
            // A letter without a number is still better than no letter, and a
            // duplicate number is a clerical problem rather than a lost request.
            Log::error('LETTER_NUMBER_SEQUENCE_FAILED', [
                'scope' => $scope,
                'error' => $e->getMessage(),
            ]);

            return LetterLog::count() + 1;
        }
    }

    /** Highest number already used by letters carrying this exact scope. */
    private function seedFor(string $scope): int
    {
        try {
            $suffix = '/' . $scope;

            $numbers = LetterLog::query()
                ->whereNotNull('nomor_surat')
                ->where('nomor_surat', 'LIKE', '%' . $suffix)
                ->pluck('nomor_surat');

            $highest = 0;

            foreach ($numbers as $nomor) {
                $leading = (int) strtok((string) $nomor, '/');
                $highest = max($highest, $leading);
            }

            // Fall back to the total letter count so a fresh scope never starts
            // below numbers issued under the old counting scheme.
            return max($highest, LetterLog::count());
        } catch (\Throwable) {
            return 0;
        }
    }

    private function buCode(?string $businessUnit): string
    {
        $map = (array) config('nastari.letters.bu_codes', []);
        $fallback = (string) config('nastari.letters.bu_code_fallback', 'CHC');
        $bu = trim((string) $businessUnit);

        if ($bu === '') {
            return $fallback;
        }

        if (isset($map[$bu])) {
            return $map[$bu];
        }

        // Tolerate casing and spacing drift between Darwinbox and kpncorp.
        foreach ($map as $name => $code) {
            if (strcasecmp(trim((string) $name), $bu) === 0) {
                return $code;
            }
        }

        return $fallback;
    }
}
