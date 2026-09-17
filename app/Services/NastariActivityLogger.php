<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes structured activity events straight into nastari_events.
 *
 * Why this exists: the WhatsApp path only ever persisted five things (a user
 * row, a call-centre ticket, a letter log, a Pandu escalation and a Ruang
 * transcript). Everything else — which menu was tapped, which lookup failed,
 * which external service timed out, how long anything took — existed only as
 * free-text lines in storage/logs, which the `daily` channel deletes after 14
 * days. Analytics had to reverse-engineer behaviour by parsing those lines.
 *
 * Compatibility is deliberate: `source`, `event_type`, `feature_key` and
 * `business_unit` keep the exact vocabulary NastariAnalyticsService already
 * queries, so no existing metric changes meaning. The requested taxonomy
 * ("employee.access.leave_balance", "external_service.timeout") is carried
 * additively in `action_key`.
 *
 * Three rules this class must never break:
 *   1. It must never throw. A logging failure cannot be allowed to break a
 *      conversation, so every write is wrapped and demoted to a warning.
 *   2. It must never block the reply. Rows are buffered and flushed once, on
 *      shutdown, after the response has gone back to Meta.
 *   3. It must never store a credential or an unnecessary personal detail.
 */
class NastariActivityLogger
{
    /**
     * action_key prefix => [source, event_type], most specific first.
     *
     * The right-hand side is the legacy vocabulary the analytics service reads.
     */
    private const CLASSIFICATION = [
        'employee.access.denied'   => ['wa_incoming', 'access_denied'],
        'employee.letter.failed'   => ['error', 'error'],
        'employee.letter'          => ['wa_incoming', 'feature_completed'],
        'employee.menu'            => ['wa_incoming', 'menu_opened'],
        'employee.navigation'      => ['wa_incoming', 'navigation'],
        'employee.session_end'     => ['wa_incoming', 'session_end'],
        'employee.not_found'       => ['lookup', 'employee_lookup_failed'],
        'employee.found'           => ['lookup', 'employee_lookup_success'],
        'employee.lookup_started'  => ['lookup', 'employee_lookup_started'],
        'employee.message'         => ['wa_incoming', 'text_message'],
        'employee.media'           => ['wa_incoming', 'media_message'],
        'employee.chat.pandu'      => ['pandu', 'pandu_question'],
        'employee.chat'            => ['wa_incoming', 'feature_selected'],
        'employee.access'          => ['wa_incoming', 'feature_selected'],
        'external_service'         => ['error', 'error'],
        'system.error'             => ['error', 'error'],
    ];

    /** Payload keys carrying anything credential-shaped are dropped outright. */
    private const FORBIDDEN_KEY_PATTERN = '/pass|token|secret|api[_-]?key|authorization|credential|cookie|session_id/i';

    private const MAX_BUFFER = 200;

    private const MAX_PAYLOAD_STRING = 500;

    private string $correlationId;

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    /** @var array<string, mixed> */
    private array $identity = [
        'phone'             => null,
        'employee_id'       => null,
        'business_unit'     => null,
        'employee_status'   => null,
        'is_known_employee' => false,
    ];

    private bool $shutdownRegistered = false;

    public function __construct()
    {
        $this->correlationId = (string) Str::uuid();
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Attaches the identity that every subsequent event in this request shares.
     *
     * @param  array<string, mixed>|null  $employee  A usernastari row as array.
     */
    public function identify(?string $phone, ?array $employee = null): void
    {
        $clean = $phone === null ? null : $this->cleanPhone($phone);

        $this->identity = [
            'phone'             => $clean,
            'employee_id'       => $employee['employee_id'] ?? null,
            'business_unit'     => $employee['group_company'] ?? null,
            'employee_status'   => $employee['employment_status'] ?? null,
            'is_known_employee' => ! empty($employee['employee_id']),
        ];
    }

    /**
     * Records one event.
     *
     * @param  string  $actionKey  e.g. 'employee.access.leave_balance'
     * @param  array<string, mixed>  $attrs  Any nastari_events column, plus
     *                                       'payload' as an array.
     */
    public function record(string $actionKey, array $attrs = []): void
    {
        if (! config('nastari.logging.enabled', true)) {
            return;
        }

        try {
            [$source, $eventType] = $this->classify($actionKey, $attrs);

            $now = $attrs['occurred_at'] ?? now();
            $timestamp = $now instanceof \DateTimeInterface ? $now : now();

            $row = [
                'occurred_at'       => $timestamp->format('Y-m-d H:i:s'),
                'event_date'        => $timestamp->format('Y-m-d'),
                'event_hour'        => (int) $timestamp->format('G'),
                'event_dow'         => (int) $timestamp->format('N'),
                'origin'            => 'live',
                'source'            => $source,
                'event_type'        => $eventType,
                'action_key'        => Str::limit($actionKey, 58, ''),

                'phone'             => $attrs['phone'] ?? $this->identity['phone'],
                'employee_id'       => $attrs['employee_id'] ?? $this->identity['employee_id'],
                'business_unit'     => $attrs['business_unit'] ?? $this->identity['business_unit'],
                'employee_status'   => $attrs['employee_status'] ?? $this->identity['employee_status'],
                'is_known_employee' => (bool) ($attrs['is_known_employee'] ?? $this->identity['is_known_employee']),
                'correlation_id'    => $this->correlationId,

                'feature_key'       => isset($attrs['feature_key']) ? Str::limit((string) $attrs['feature_key'], 58, '') : null,
                'feature_label'     => isset($attrs['feature_label']) ? Str::limit((string) $attrs['feature_label'], 118, '') : null,
                'message_type'      => $attrs['message_type'] ?? null,
                'outcome'           => $attrs['outcome'] ?? null,
                'duration_ms'       => isset($attrs['duration_ms']) ? max(0, (int) $attrs['duration_ms']) : null,

                'service'           => isset($attrs['service']) ? Str::limit((string) $attrs['service'], 38, '') : null,
                'endpoint'          => isset($attrs['endpoint']) ? Str::limit((string) $attrs['endpoint'], 158, '') : null,
                'error_type'        => isset($attrs['error_type']) ? Str::limit((string) $attrs['error_type'], 38, '') : null,
                'http_status'       => isset($attrs['http_status']) ? (int) $attrs['http_status'] : null,

                'payload'           => $this->encodePayload($attrs['payload'] ?? null),

                // Live rows have no source log line to hash, but the column is
                // unique and NOT NULL, so a random value keeps insertOrIgnore
                // working the same way it does for ingested rows.
                'fingerprint'       => sha1($this->correlationId . '|' . $actionKey . '|' . microtime(true) . '|' . count($this->buffer)),
                'created_at'        => now(),
            ];

            $this->buffer[] = $row;
            $this->registerShutdownFlush();

            if (count($this->buffer) >= self::MAX_BUFFER) {
                $this->flush();
            }
        } catch (\Throwable $e) {
            Log::warning('ACTIVITY_LOG_RECORD_FAILED', [
                'action_key' => $actionKey,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience wrapper for a feature access event.
     */
    public function feature(string $featureKey, string $label, array $attrs = []): void
    {
        $this->record('employee.access.' . $featureKey, array_merge([
            'feature_key'   => $featureKey,
            'feature_label' => $label,
            'outcome'       => 'success',
        ], $attrs));
    }

    /**
     * Records a failed call to something outside this application.
     *
     * @param  string  $errorType  timeout|connection|http_error|unavailable|...
     */
    public function externalFailure(string $service, string $endpoint, string $errorType, array $attrs = []): void
    {
        $this->record('external_service.' . $errorType, array_merge([
            'service'       => $service,
            'endpoint'      => $endpoint,
            'error_type'    => $errorType,
            'outcome'       => 'error',
            'feature_label' => $this->errorLabel($errorType),
        ], $attrs));
    }

    /**
     * Writes the buffered rows. Safe to call more than once.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $rows = $this->buffer;
        $this->buffer = [];

        try {
            DB::table('nastari_events')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            // The events are lost, but the conversation already succeeded. The
            // file log keeps a trace so the gap is explainable.
            Log::warning('ACTIVITY_LOG_FLUSH_FAILED', [
                'rows'  => count($rows),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{0:string, 1:string} */
    private function classify(string $actionKey, array $attrs): array
    {
        if (isset($attrs['source'], $attrs['event_type'])) {
            return [(string) $attrs['source'], (string) $attrs['event_type']];
        }

        foreach (self::CLASSIFICATION as $prefix => $pair) {
            if ($actionKey === $prefix || str_starts_with($actionKey, $prefix . '.')) {
                return $pair;
            }
        }

        return ['app', 'other'];
    }

    /**
     * Payloads are small, bounded and credential-free by construction.
     */
    private function encodePayload($payload): ?string
    {
        if (empty($payload)) {
            return null;
        }

        if (! is_array($payload)) {
            $payload = ['value' => $payload];
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            if (preg_match(self::FORBIDDEN_KEY_PATTERN, (string) $key)) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $value = Str::limit(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '', self::MAX_PAYLOAD_STRING, '');
            } elseif (is_string($value)) {
                $value = Str::limit($value, self::MAX_PAYLOAD_STRING, '');
            }

            $clean[$key] = $value;
        }

        if ($clean === []) {
            return null;
        }

        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /** Indonesian labels, matching the strings the ingestor already produces. */
    private function errorLabel(string $errorType): string
    {
        return match ($errorType) {
            'timeout'     => 'Timeout layanan eksternal',
            'connection'  => 'Kegagalan koneksi',
            'unavailable' => 'Layanan eksternal tidak tersedia',
            'http_error'  => 'Respons error layanan eksternal',
            default       => 'Error lain',
        };
    }

    /**
     * Mirrors DarwinboxService::cleanMobileNumber so events join to usernastari.
     */
    private function cleanPhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '620')) {
            $digits = '62' . substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        return Str::limit($digits, 24, '');
    }

    /**
     * Flushing on shutdown keeps the insert off the reply path: WhatsApp has
     * already had its 200 by the time this runs.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function () {
            $this->flush();
        });
    }
}
