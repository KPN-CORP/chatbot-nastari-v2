<?php

namespace App\Support;

/**
 * The single description of every bot feature: its interactive payload id, the
 * slug analytics groups it by, its Indonesian label, and whether an inactive
 * employee may use it.
 *
 * One registry rather than three lists on purpose. Feature attribution for
 * logging, the menu the user is shown, and the authorisation check on the way
 * in all have to agree. When they lived in separate places (labels in
 * WhatsAppSenderService, dispatch in WebhookController's switch, nothing at all
 * for authorisation) they drifted: the ingestor had to guess a feature from the
 * button text, and a renumbered menu item silently became a new feature.
 *
 * KIND
 *   action      returns data or completes a task; this is real usage
 *   navigation  moving around the menus; counted separately from usage
 *
 * INACTIVE ACCESS
 *   Business rule: employees.deleted_at IS NOT NULL means INACTIVE, and an
 *   inactive employee may only reach their own employee data and Ruang.
 *   Everything else is refused in the backend, not merely hidden.
 */
final class NastariFeatures
{
    public const KIND_ACTION = 'action';

    public const KIND_NAVIGATION = 'navigation';

    /**
     * @var array<string, array{key:string, label:string, kind:string, group:string, inactive:bool}>
     */
    private const MAP = [
        // -- navigation ---------------------------------------------------
        'main_menu' => ['key' => 'back_to_main', 'label' => 'Kembali ke Menu Utama', 'kind' => self::KIND_NAVIGATION, 'group' => 'nav', 'inactive' => true],
        // Denied for inactive employees even though personal_data is allowed:
        // their main menu links straight to personal_data, so reaching this
        // sub-menu would only show two options that are then refused.
        'sub_menu_data_karyawan' => ['key' => 'data_karyawan', 'label' => 'Data Karyawan', 'kind' => self::KIND_NAVIGATION, 'group' => 'nav', 'inactive' => false],
        'sub_menu_time_management' => ['key' => 'catatan_kehadiran', 'label' => 'Catatan Kehadiran', 'kind' => self::KIND_NAVIGATION, 'group' => 'nav', 'inactive' => false],
        'sub_menu_tim_saya' => ['key' => 'informasi_tim_saya', 'label' => 'Informasi Tim Saya', 'kind' => self::KIND_NAVIGATION, 'group' => 'nav', 'inactive' => false],
        'generate_surat' => ['key' => 'generate_surat', 'label' => 'Generate Surat', 'kind' => self::KIND_NAVIGATION, 'group' => 'nav', 'inactive' => false],

        // -- employee data ------------------------------------------------
        'personal_data' => ['key' => 'personal_data', 'label' => 'Data Karyawan', 'kind' => self::KIND_ACTION, 'group' => 'data_karyawan', 'inactive' => true],
        'dependent_data' => ['key' => 'dependent_data', 'label' => 'Data Keluarga', 'kind' => self::KIND_ACTION, 'group' => 'data_karyawan', 'inactive' => false],
        'medical_plafon' => ['key' => 'medical_plafon', 'label' => 'Plafon Medis', 'kind' => self::KIND_ACTION, 'group' => 'data_karyawan', 'inactive' => false],

        // -- attendance ---------------------------------------------------
        'leave_balance' => ['key' => 'leave_balance', 'label' => 'Saldo Cuti', 'kind' => self::KIND_ACTION, 'group' => 'kehadiran', 'inactive' => false],
        'work_schedule' => ['key' => 'work_schedule', 'label' => 'Cek Jadwal Kerja', 'kind' => self::KIND_ACTION, 'group' => 'kehadiran', 'inactive' => false],
        'single_punch_check' => ['key' => 'single_punch', 'label' => 'Single Punch', 'kind' => self::KIND_ACTION, 'group' => 'kehadiran', 'inactive' => false],
        'late_history' => ['key' => 'late_history', 'label' => 'Riwayat Terlambat', 'kind' => self::KIND_ACTION, 'group' => 'kehadiran', 'inactive' => false],
        'overtime_data' => ['key' => 'overtime_data', 'label' => 'Data Overtime', 'kind' => self::KIND_ACTION, 'group' => 'kehadiran', 'inactive' => false],

        // -- manager ------------------------------------------------------
        'my_team_list' => ['key' => 'my_team_list', 'label' => 'Tim Saya', 'kind' => self::KIND_ACTION, 'group' => 'manager', 'inactive' => false],
        'team_presence' => ['key' => 'team_presence', 'label' => 'Laporan Presensi Harian', 'kind' => self::KIND_ACTION, 'group' => 'manager', 'inactive' => false],
        'leave_approval' => ['key' => 'leave_approval', 'label' => 'Persetujuan Cuti', 'kind' => self::KIND_ACTION, 'group' => 'manager', 'inactive' => false],
        'late_records_team' => ['key' => 'late_records_team', 'label' => 'Riwayat Terlambat Tim', 'kind' => self::KIND_ACTION, 'group' => 'manager', 'inactive' => false],
        'single_punch_team' => ['key' => 'single_punch_team', 'label' => 'Single Punch Tim', 'kind' => self::KIND_ACTION, 'group' => 'manager', 'inactive' => false],
        'manager_assistant' => ['key' => 'manager_assistant', 'label' => 'Nastari AI', 'kind' => self::KIND_ACTION, 'group' => 'manager', 'inactive' => false],

        // -- letters ------------------------------------------------------
        'surat_ket_kerja' => ['key' => 'surat_ket_kerja', 'label' => 'Surat Keterangan Kerja', 'kind' => self::KIND_ACTION, 'group' => 'surat', 'inactive' => false],
        'surat_visa_id' => ['key' => 'surat_visa_id', 'label' => 'Surat Pengajuan Visa ID', 'kind' => self::KIND_ACTION, 'group' => 'surat', 'inactive' => false],
        'surat_visa_en' => ['key' => 'surat_visa_en', 'label' => 'Surat Pengajuan Visa EN', 'kind' => self::KIND_ACTION, 'group' => 'surat', 'inactive' => false],

        // -- conversational -----------------------------------------------
        'ruang_placeholder' => ['key' => 'ruang', 'label' => 'Ruang', 'kind' => self::KIND_ACTION, 'group' => 'chat', 'inactive' => true],
        'faq' => ['key' => 'pandu', 'label' => 'PANDU', 'kind' => self::KIND_ACTION, 'group' => 'chat', 'inactive' => false],
        'ruang_to_pandu' => ['key' => 'pandu', 'label' => 'Tanya di Pandu', 'kind' => self::KIND_ACTION, 'group' => 'chat', 'inactive' => false],
        // Staying in Ruang is part of Ruang, so it stays open to inactive
        // employees. ("Selesai" is intercepted by title in handle() and
        // arrives as text, but is registered here so the id is never orphaned.)
        'ruang_stay' => ['key' => 'ruang', 'label' => 'Ruang — lanjut cerita', 'kind' => self::KIND_NAVIGATION, 'group' => 'chat', 'inactive' => true],
        'ruang_done' => ['key' => 'session_end', 'label' => 'Selesai', 'kind' => self::KIND_NAVIGATION, 'group' => 'chat', 'inactive' => true],

        // -- support ------------------------------------------------------
        'call_center_menu' => ['key' => 'hc_system_desk', 'label' => 'HC System Desk', 'kind' => self::KIND_ACTION, 'group' => 'support', 'inactive' => false],
    ];

    /**
     * Conversation states an inactive employee is allowed to continue in.
     *
     * Gating only the buttons would be bypassable: the letter and Pandu flows
     * carry on through plain text messages, so the state has to be checked too.
     */
    private const INACTIVE_STATES = [
        'idle',
        'in_ruang_session',
    ];

    /** @return array{key:string, label:string, kind:string, group:string, inactive:bool}|null */
    public static function find(?string $interactiveId): ?array
    {
        if ($interactiveId === null) {
            return null;
        }

        return self::MAP[$interactiveId] ?? null;
    }

    public static function key(?string $interactiveId): ?string
    {
        return self::find($interactiveId)['key'] ?? null;
    }

    public static function label(?string $interactiveId): ?string
    {
        return self::find($interactiveId)['label'] ?? null;
    }

    public static function isNavigation(?string $interactiveId): bool
    {
        return (self::find($interactiveId)['kind'] ?? null) === self::KIND_NAVIGATION;
    }

    /**
     * Whether an inactive employee may use this interactive id.
     *
     * Unknown ids default to *not* allowed: a payload that is not in the
     * registry is either a new feature that has not been classified yet or a
     * hand-crafted one, and neither should be handed to an inactive employee.
     */
    public static function allowedWhenInactive(?string $interactiveId): bool
    {
        $feature = self::find($interactiveId);

        return $feature !== null && $feature['inactive'] === true;
    }

    public static function stateAllowedWhenInactive(?string $state): bool
    {
        return in_array($state ?: 'idle', self::INACTIVE_STATES, true);
    }

    /** @return array<int, string> Interactive ids an inactive employee may use. */
    public static function inactiveWhitelist(): array
    {
        $allowed = [];

        foreach (self::MAP as $id => $feature) {
            if ($feature['inactive']) {
                $allowed[] = $id;
            }
        }

        return $allowed;
    }
}
