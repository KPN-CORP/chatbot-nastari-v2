<?php

namespace Tests\Unit;

use App\Services\EmployeeAccessPolicy;
use App\Support\NastariFeatures;
use PHPUnit\Framework\TestCase;

/**
 * The active/inactive business rule.
 *
 *   employees.deleted_at IS NULL     -> ACTIVE, full access
 *   employees.deleted_at IS NOT NULL -> INACTIVE, only own employee data + Ruang
 *
 * No framework or database needed: the policy is a pure decision over the
 * feature registry, which is exactly why it can be enforced everywhere.
 */
class EmployeeAccessPolicyTest extends TestCase
{
    private EmployeeAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new EmployeeAccessPolicy();
    }

    private function employee(string $status): array
    {
        return ['employee_id' => '01123070004', 'employment_status' => $status];
    }

    public function test_inactive_is_detected_only_for_the_inactive_status(): void
    {
        $this->assertTrue($this->policy->isInactive($this->employee('inactive')));
        $this->assertFalse($this->policy->isInactive($this->employee('active')));
        $this->assertFalse($this->policy->isInactive($this->employee('unknown')));
        $this->assertFalse($this->policy->isInactive([]));
        $this->assertFalse($this->policy->isInactive(null));
    }

    public function test_active_employee_may_use_every_registered_feature(): void
    {
        $active = $this->employee('active');

        foreach (['personal_data', 'dependent_data', 'medical_plafon', 'leave_balance',
                  'overtime_data', 'leave_approval', 'manager_assistant', 'surat_ket_kerja',
                  'surat_visa_en', 'faq', 'ruang_placeholder', 'call_center_menu'] as $id) {
            $this->assertTrue(
                $this->policy->allowsInteractive($active, $id),
                "Active employee should be allowed to use {$id}"
            );
        }
    }

    public function test_inactive_employee_may_only_use_personal_data_and_ruang(): void
    {
        $inactive = $this->employee('inactive');

        $this->assertTrue($this->policy->allowsInteractive($inactive, 'personal_data'));
        $this->assertTrue($this->policy->allowsInteractive($inactive, 'ruang_placeholder'));
        $this->assertTrue($this->policy->allowsInteractive($inactive, 'main_menu'));

        foreach (['dependent_data', 'medical_plafon', 'leave_balance', 'work_schedule',
                  'late_history', 'single_punch_check', 'overtime_data', 'my_team_list',
                  'team_presence', 'leave_approval', 'late_records_team', 'single_punch_team',
                  'manager_assistant', 'generate_surat', 'surat_ket_kerja', 'surat_visa_id',
                  'surat_visa_en', 'faq', 'ruang_to_pandu', 'call_center_menu',
                  'sub_menu_time_management', 'sub_menu_tim_saya', 'sub_menu_data_karyawan'] as $id) {
            $this->assertFalse(
                $this->policy->allowsInteractive($inactive, $id),
                "Inactive employee must NOT be allowed to use {$id}"
            );
        }
    }

    /**
     * The refusal must not depend on the menu: WhatsApp interactive replies are
     * just an id, and an old menu message can be re-tapped at any time.
     */
    public function test_inactive_employee_is_denied_unknown_and_crafted_payload_ids(): void
    {
        $inactive = $this->employee('inactive');

        foreach (['', 'made_up_id', 'act_approve_123_01123070004', 'visa_tanggungan_company',
                  'hear_escalate_yes', 'nfm_reply', 'leave_approval'] as $id) {
            $this->assertFalse(
                $this->policy->allowsInteractive($inactive, $id),
                "Unregistered payload id '{$id}' must default to denied for an inactive employee"
            );
        }

        $this->assertFalse($this->policy->allowsInteractive($inactive, null));
    }

    /**
     * A sync outage must never lock a real employee out of the bot.
     */
    public function test_unknown_status_fails_open(): void
    {
        $unknown = $this->employee('unknown');

        $this->assertTrue($this->policy->allowsInteractive($unknown, 'leave_balance'));
        $this->assertTrue($this->policy->allowsInteractive($unknown, 'surat_ket_kerja'));
        $this->assertTrue($this->policy->allowsState($unknown, 'waiting_for_surat_institusi'));
    }

    /**
     * The letter and Pandu flows continue through plain text, so gating only
     * the button would leave a bypass: an inactive employee could carry on in a
     * flow they had already started.
     */
    public function test_inactive_employee_may_only_continue_idle_or_ruang_states(): void
    {
        $inactive = $this->employee('inactive');

        $this->assertTrue($this->policy->allowsState($inactive, 'idle'));
        $this->assertTrue($this->policy->allowsState($inactive, 'in_ruang_session'));
        $this->assertTrue($this->policy->allowsState($inactive, null));

        foreach (['waiting_for_hear_question', 'waiting_for_surat_kebutuhan',
                  'waiting_for_surat_institusi', 'waiting_for_visa_destinasi_ID',
                  'waiting_for_visa_tanggungan_EN', 'in_manager_ai_session',
                  'ai_session_clarification', 'waiting_for_call_center_complaint',
                  'waiting_for_call_center_photo', 'waiting_for_rejection_reason',
                  'waiting_for_escalation_confirmation'] as $state) {
            $this->assertFalse(
                $this->policy->allowsState($inactive, $state),
                "Inactive employee must NOT be able to continue in state {$state}"
            );
        }
    }

    public function test_active_employee_may_continue_any_state(): void
    {
        $active = $this->employee('active');

        foreach (['idle', 'in_ruang_session', 'waiting_for_hear_question',
                  'waiting_for_surat_institusi', 'in_manager_ai_session'] as $state) {
            $this->assertTrue($this->policy->allowsState($active, $state));
        }
    }

    public function test_denial_message_names_both_permitted_features(): void
    {
        $message = $this->policy->denialMessage();

        $this->assertStringContainsString('Data Karyawan', $message);
        $this->assertStringContainsString('Ruang', $message);
        $this->assertStringContainsString('tidak aktif', $message);
    }

    public function test_denial_is_attributed_to_a_feature_for_analytics(): void
    {
        $known = $this->policy->denialFeature('leave_balance');
        $this->assertSame('leave_balance', $known['feature_key']);
        $this->assertSame('Saldo Cuti', $known['feature_label']);

        $unknown = $this->policy->denialFeature('made_up_id');
        $this->assertSame('unknown', $unknown['feature_key']);
    }

    public function test_registry_whitelist_matches_the_business_rule(): void
    {
        $whitelist = NastariFeatures::inactiveWhitelist();

        // Exactly the two features the rule permits, plus the navigation
        // needed to reach them.
        $this->assertContains('personal_data', $whitelist);
        $this->assertContains('ruang_placeholder', $whitelist);
        $this->assertNotContains('leave_balance', $whitelist);
        $this->assertNotContains('faq', $whitelist);
        $this->assertNotContains('surat_ket_kerja', $whitelist);
    }

    public function test_navigation_and_actions_are_distinguished(): void
    {
        $this->assertTrue(NastariFeatures::isNavigation('main_menu'));
        $this->assertTrue(NastariFeatures::isNavigation('sub_menu_time_management'));
        $this->assertFalse(NastariFeatures::isNavigation('leave_balance'));
        $this->assertFalse(NastariFeatures::isNavigation('made_up_id'));
    }
}
