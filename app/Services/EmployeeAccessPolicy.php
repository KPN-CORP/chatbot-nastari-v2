<?php

namespace App\Services;

use App\Support\NastariFeatures;

/**
 * Decides what an inactive employee is allowed to do in the bot.
 *
 * Business rule:
 *   employees.deleted_at IS NULL     -> ACTIVE, everything as before
 *   employees.deleted_at IS NOT NULL -> INACTIVE, only their own employee data
 *                                       and Ruang
 *
 * Enforced in the backend, not by hiding menu rows. Hiding alone is not
 * authorisation: WhatsApp interactive replies are just a payload id, the
 * webhook has no request authentication beyond the verify-token handshake, and
 * a previously-received menu message can be re-tapped at any time. Multi-step
 * features (letters, Pandu) also continue through plain text, so the
 * conversation state has to be checked as well as the button.
 *
 * Fail-open on 'unknown': an employee whose status the sync could not determine
 * keeps full access. Locking real employees out because a sync failed would be
 * a worse outcome than briefly letting a leaver read their own data, and every
 * refusal is recorded so false positives surface on the dashboard the same day.
 */
class EmployeeAccessPolicy
{
    /**
     * @param  array<string, mixed>|null  $employee  A usernastari row as array.
     */
    public function isInactive(?array $employee): bool
    {
        return ($employee['employment_status'] ?? 'unknown') === 'inactive';
    }

    /**
     * @param  array<string, mixed>|null  $employee
     */
    public function allowsInteractive(?array $employee, ?string $interactiveId): bool
    {
        if (! $this->isInactive($employee)) {
            return true;
        }

        return NastariFeatures::allowedWhenInactive($interactiveId);
    }

    /**
     * @param  array<string, mixed>|null  $employee
     */
    public function allowsState(?array $employee, ?string $state): bool
    {
        if (! $this->isInactive($employee)) {
            return true;
        }

        return NastariFeatures::stateAllowedWhenInactive($state);
    }

    /**
     * What analytics records when access is refused.
     */
    public function denialFeature(?string $interactiveId): array
    {
        $feature = NastariFeatures::find($interactiveId);

        return [
            'feature_key'   => $feature['key'] ?? 'unknown',
            'feature_label' => $feature['label'] ?? ($interactiveId ?: 'Tidak dikenal'),
        ];
    }

    public function denialMessage(): string
    {
        return "Mohon maaf, status kepegawaian kamu sudah tidak aktif di HC System, jadi fitur ini tidak bisa diakses lagi. 🙏\n\n"
            . "Yang masih bisa kamu akses:\n"
            . "1️⃣ *Data Karyawan* — lihat data pribadi kamu\n"
            . "2️⃣ *Ruang* — kalau ada yang ingin kamu ceritakan\n\n"
            . "Kalau kamu merasa status ini keliru, silakan hubungi tim HCO Unit Bisnis kamu ya. Terima kasih! 😊";
    }
}
