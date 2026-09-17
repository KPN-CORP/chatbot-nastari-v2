<?php

namespace Tests\Feature;

use App\Services\NastariActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Structured activity logging.
 *
 * The two properties that matter most are compatibility and safety:
 *
 *   compatibility  live rows must keep the legacy source/event_type vocabulary
 *                  that NastariAnalyticsService and all nine analytics tabs
 *                  already query, or every existing metric silently reads zero.
 *   safety         nothing credential-shaped may ever reach the event store,
 *                  and a logging failure must never break a conversation.
 */
class NastariActivityLoggerTest extends TestCase
{
    use RefreshDatabase;

    private function logger(): NastariActivityLogger
    {
        return new NastariActivityLogger();
    }

    private function lastEvent(): ?object
    {
        return DB::table('nastari_events')->latest('id')->first();
    }

    public function test_a_feature_access_event_keeps_the_legacy_analytics_vocabulary(): void
    {
        $logger = $this->logger();
        $logger->identify('081234567890', [
            'employee_id'       => '01123070004',
            'group_company'     => 'Downstream',
            'employment_status' => 'active',
        ]);
        $logger->feature('leave_balance', 'Saldo Cuti', ['duration_ms' => 412]);
        $logger->flush();

        $event = $this->lastEvent();

        // Legacy vocabulary the existing metrics read.
        $this->assertSame('wa_incoming', $event->source);
        $this->assertSame('feature_selected', $event->event_type);
        $this->assertSame('leave_balance', $event->feature_key);
        $this->assertSame('Saldo Cuti', $event->feature_label);

        // The requested taxonomy, carried additively.
        $this->assertSame('employee.access.leave_balance', $event->action_key);

        $this->assertSame('live', $event->origin);
        $this->assertSame('01123070004', $event->employee_id);
        $this->assertSame('Downstream', $event->business_unit);
        $this->assertSame('active', $event->employee_status);
        $this->assertSame(412, (int) $event->duration_ms);
        $this->assertSame(1, (int) $event->is_known_employee);
    }

    public function test_the_phone_is_normalised_so_events_join_to_usernastari(): void
    {
        $logger = $this->logger();
        $logger->identify('081234567890');
        $logger->record('employee.message');
        $logger->flush();

        $this->assertSame('6281234567890', $this->lastEvent()->phone);
    }

    /**
     * @dataProvider classificationProvider
     */
    public function test_action_keys_map_to_the_expected_source_and_event_type(string $actionKey, string $source, string $eventType): void
    {
        $logger = $this->logger();
        $logger->record($actionKey);
        $logger->flush();

        $event = $this->lastEvent();

        $this->assertSame($source, $event->source, "source for {$actionKey}");
        $this->assertSame($eventType, $event->event_type, "event_type for {$actionKey}");
    }

    public static function classificationProvider(): array
    {
        return [
            ['employee.access.leave_balance', 'wa_incoming', 'feature_selected'],
            ['employee.access.employee_data', 'wa_incoming', 'feature_selected'],
            ['employee.access.denied',        'wa_incoming', 'access_denied'],
            ['employee.menu.data_karyawan',   'wa_incoming', 'menu_opened'],
            ['employee.navigation.back',      'wa_incoming', 'navigation'],
            ['employee.session_end',          'wa_incoming', 'session_end'],
            ['employee.message',              'wa_incoming', 'text_message'],
            ['employee.chat.ruang',           'wa_incoming', 'feature_selected'],
            ['employee.chat.pandu',           'pandu',       'pandu_question'],
            ['employee.not_found',            'lookup',      'employee_lookup_failed'],
            ['employee.found',                'lookup',      'employee_lookup_success'],
            ['employee.lookup_started',       'lookup',      'employee_lookup_started'],
            ['employee.letter.generated',     'wa_incoming', 'feature_completed'],
            ['employee.letter.failed',        'error',       'error'],
            ['external_service.timeout',      'error',       'error'],
            ['external_service.connection',   'error',       'error'],
            ['system.error',                  'error',       'error'],
        ];
    }

    public function test_the_denied_prefix_is_matched_before_the_broader_access_prefix(): void
    {
        // 'employee.access.denied' must not be swallowed by 'employee.access'.
        $logger = $this->logger();
        $logger->record('employee.access.denied', ['feature_key' => 'leave_balance']);
        $logger->flush();

        $this->assertSame('access_denied', $this->lastEvent()->event_type);
    }

    public function test_an_external_failure_records_the_service_endpoint_and_error_type(): void
    {
        $logger = $this->logger();
        $logger->externalFailure('pandu_rag', 'POST /ask', 'timeout', [
            'duration_ms' => 20014,
            'http_status' => null,
        ]);
        $logger->flush();

        $event = $this->lastEvent();

        $this->assertSame('error', $event->source);
        $this->assertSame('external_service.timeout', $event->action_key);
        $this->assertSame('pandu_rag', $event->service);
        $this->assertSame('POST /ask', $event->endpoint);
        $this->assertSame('timeout', $event->error_type);
        $this->assertSame(20014, (int) $event->duration_ms);
        $this->assertSame('Timeout layanan eksternal', $event->feature_label);
    }

    public function test_credential_shaped_payload_keys_are_dropped(): void
    {
        $logger = $this->logger();
        $logger->record('employee.chat.pandu', [
            'payload' => [
                'question'      => 'Bagaimana policy cuti tahunan?',
                'api_key'       => 'should-not-be-stored',
                'access_token'  => 'should-not-be-stored',
                'Authorization' => 'Basic should-not-be-stored',
                'password'      => 'should-not-be-stored',
                'session_id'    => 'should-not-be-stored',
                'secret'        => 'should-not-be-stored',
            ],
        ]);
        $logger->flush();

        $payload = json_decode($this->lastEvent()->payload, true);

        $this->assertSame(['question' => 'Bagaimana policy cuti tahunan?'], $payload);

        // Belt and braces: the serialised column must not contain the value at all.
        $this->assertStringNotContainsString('should-not-be-stored', (string) $this->lastEvent()->payload);
    }

    public function test_long_payload_strings_are_truncated(): void
    {
        $logger = $this->logger();
        $logger->record('employee.chat.pandu', ['payload' => ['question' => str_repeat('a', 2000)]]);
        $logger->flush();

        $payload = json_decode($this->lastEvent()->payload, true);

        $this->assertLessThanOrEqual(500, strlen($payload['question']));
    }

    public function test_every_event_in_one_request_shares_a_correlation_id(): void
    {
        $logger = $this->logger();
        $logger->identify('081234567890');
        $logger->record('employee.menu.data_karyawan');
        $logger->record('employee.access.personal_data');
        $logger->flush();

        $ids = DB::table('nastari_events')->distinct()->pluck('correlation_id');

        $this->assertCount(1, $ids);
        $this->assertSame($logger->correlationId(), $ids->first());
    }

    public function test_flush_is_safe_to_call_repeatedly_and_does_not_duplicate(): void
    {
        $logger = $this->logger();
        $logger->record('employee.message');

        $logger->flush();
        $logger->flush();
        $logger->flush();

        $this->assertSame(1, DB::table('nastari_events')->count());
    }

    public function test_recording_can_be_disabled_by_configuration(): void
    {
        config(['nastari.logging.enabled' => false]);

        $logger = $this->logger();
        $logger->record('employee.message');
        $logger->flush();

        $this->assertSame(0, DB::table('nastari_events')->count());
    }

    /**
     * A logging fault must degrade to a warning, never surface to the employee.
     */
    public function test_a_broken_event_store_does_not_throw(): void
    {
        $logger = $this->logger();
        $logger->record('employee.message');

        DB::statement('DROP TABLE nastari_events');

        $logger->flush(); // must not throw

        $this->assertTrue(true);
    }

    public function test_an_unclassified_action_key_still_records_rather_than_being_lost(): void
    {
        $logger = $this->logger();
        $logger->record('something.entirely.new');
        $logger->flush();

        $event = $this->lastEvent();

        $this->assertSame('app', $event->source);
        $this->assertSame('other', $event->event_type);
        $this->assertSame('something.entirely.new', $event->action_key);
    }

    public function test_explicit_source_and_event_type_override_the_classification(): void
    {
        $logger = $this->logger();
        $logger->record('employee.access.leave_balance', [
            'source'     => 'pandu',
            'event_type' => 'pandu_question',
        ]);
        $logger->flush();

        $event = $this->lastEvent();

        $this->assertSame('pandu', $event->source);
        $this->assertSame('pandu_question', $event->event_type);
    }
}
