<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Traits\ScopedByRole;

class Hear extends Model
{
    use SoftDeletes;
    use ScopedByRole;

    protected $guarded = ['id'];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function scopeScoped($query)
    {
        if (!Auth::check()) {
            return $query;
        }

        $user = Auth::user();

        $roleIds = DB::connection('mysql')->table('role_user')
            ->where('user_id', $user->employee_id)
            ->pluck('role_id');

        $roles = DB::connection('mysql')->table('roles')
            ->whereIn('id', $roleIds)
            ->get();

        if ($roles->contains('name', 'Super Admin')) {
            return $query;
        }

        $scopeBUs = [];
        foreach ($roles as $role) {
            if (!empty($role->scope_bu)) {
                $bus = $role->scope_bu;
                if (is_string($bus)) {
                    $decoded = json_decode($bus, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $bus = $decoded;
                    } elseif (str_contains($bus, ',')) {
                        $bus = explode(',', $bus);
                    } else {
                        $bus = [$bus];
                    }
                }
                if (is_array($bus)) {
                    $scopeBUs = array_merge($scopeBUs, $bus);
                } else {
                    $scopeBUs[] = $bus;
                }
            }
        }

        $scopeBUs = array_unique(array_map(function($val) {
            return trim($val, '[]" ');
        }, $scopeBUs));

        if (!empty($scopeBUs)) {
            $matchingEmployeeIds = Employee::where(function($q) use ($scopeBUs) {
                foreach ($scopeBUs as $bu) {
                    $q->orWhere('group_company', 'LIKE', "%{$bu}%");
                }
            })->pluck('employee_id')->toArray();

            // KEMBALIKAN AKSES KE DIRI SENDIRI (User Friendly)
            return $query->where(function($q) use ($matchingEmployeeIds, $user) {
                $q->whereIn('employee_id', $matchingEmployeeIds)
                  ->orWhere('employee_id', $user->employee_id);
            });
        }

        return $query->where('employee_id', $user->employee_id);
    }
}