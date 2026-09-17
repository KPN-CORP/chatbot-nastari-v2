<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\ScopedByRole;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KbDocument extends Model
{
    use ScopedByRole;

    protected $table = 'kb_documents';
    protected $guarded = ['id'];
    protected $casts = [
        'file_size' => 'float',
    ];

    public function scopeScoped($query)
    {
        $user = Auth::user();

        if (!$user || empty($user->employee_id)) {
            return $query->whereRaw('1 = 0');
        }

        $roleIds = DB::connection('mysql')
            ->table('role_user')
            ->where('user_id', $user->employee_id)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $roles = Role::whereIn('id', $roleIds)->get();

        if ($roles->contains(fn($r) => strtolower($r->name) === 'super admin')) {
            return $query;
        }

        return $query->where(function ($mainQuery) use ($roles) {
            foreach ($roles as $role) {
                $bus = $this->parseScopeData($role->scope_bu);

                if (!empty($bus)) {
                    $mainQuery->orWhere(function ($q) use ($bus) {
                        foreach ($bus as $bu) {
                            $q->orWhere('business_unit', 'LIKE', "%{$bu}%");
                        }
                    });
                }
            }
        });
    }

    private function parseScopeData($data)
    {
        if (empty($data)) return [];
        if (is_array($data)) return $data;

        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (is_string($data) && str_contains($data, ',')) {
            return array_map('trim', explode(',', $data));
        }

        return [trim($data)];
    }
}