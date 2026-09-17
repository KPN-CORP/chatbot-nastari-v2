<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Traits\ScopedByRole;

/**
 * A person Nastari knows about.
 *
 * Two populations live in this table and must not be conflated:
 *
 *   registration_status = 'registered'      has actually used the WhatsApp bot
 *   registration_status = 'pre_registered'  seeded by the daily employee sync
 *                                           from kpncorp.employees so the
 *                                           dashboard can show HRIS coverage
 *
 * Anything that reports on adoption ("total user terdaftar") must use
 * registered(), or the number silently becomes headcount instead.
 *
 * employment_status mirrors employees.deleted_at: 'active', 'inactive', or
 * 'unknown' when the sync could not match an employees row. 'unknown' counts
 * as permitted, so a sync outage cannot lock anyone out of the bot.
 */
class UserNastari extends Model
{
    use ScopedByRole;

    protected $table = 'usernastari';
    protected $guarded = ['id'];

    protected $casts = [
        'employee_status_synced_at' => 'datetime',
        'employee_deleted_at'       => 'datetime',
    ];

    /** People who have actually used the bot. */
    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where($this->getTable() . '.registration_status', 'registered');
    }

    /** Seeded from HRIS, never yet in a conversation. */
    public function scopePreRegistered(Builder $query): Builder
    {
        return $query->where($this->getTable() . '.registration_status', 'pre_registered');
    }

    public function scopeActiveEmployees(Builder $query): Builder
    {
        return $query->whereIn($this->getTable() . '.employment_status', ['active', 'unknown']);
    }

    public function scopeInactiveEmployees(Builder $query): Builder
    {
        return $query->where($this->getTable() . '.employment_status', 'inactive');
    }

    public function isInactive(): bool
    {
        return $this->employment_status === 'inactive';
    }

    public function call_centers()
    {
        return $this->hasMany(CallCenter::class, 'employee_id', 'employee_id');
    }

    public function hears()
    {
        return $this->hasMany(Hear::class, 'employee_id', 'employee_id');
    }
}
