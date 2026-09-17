<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\Role;

trait ScopedByRole
{
    public function scopeScoped(Builder $query)
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

        $tableName = $query->getModel()->getTable();
        
        $companyCol = 'company_name';
        $locationCol = 'work_area_code'; 

        // UPDATED: Tambahkan 'call_centers' ke daftar ini
        if (in_array($tableName, ['usernastari', 'daily_chat_logs', 'call_centers'])) {
            $companyCol = 'contribution_level';
            $locationCol = 'office_area';
        }

        return $query->where(function ($mainQuery) use ($roles, $companyCol, $locationCol) {
            foreach ($roles as $role) {
                $bus = $this->parseScopeData($role->scope_bu);
                $companies = $this->parseScopeData($role->scope_company);
                $locations = $this->parseScopeData($role->scope_location);

                if (empty($bus) && empty($companies) && empty($locations)) {
                    continue; 
                }

                $mainQuery->orWhere(function ($roleQuery) use ($bus, $companies, $locations, $companyCol, $locationCol) {
                    
                    if (!empty($bus)) {
                        $roleQuery->where(function ($q) use ($bus) {
                            foreach ($bus as $bu) {
                                $q->orWhere('group_company', 'LIKE', "%{$bu}%");
                            }
                        });
                    }

                    if (!empty($companies)) {
                        $roleQuery->where(function ($q) use ($companies, $companyCol) {
                            foreach ($companies as $comp) {
                                $q->orWhere($companyCol, 'LIKE', "%{$comp}%");
                            }
                        });
                    }

                    if (!empty($locations)) {
                        $roleQuery->where(function ($q) use ($locations, $locationCol) {
                            foreach ($locations as $loc) {
                                $q->orWhere($locationCol, 'LIKE', "%{$loc}%");
                            }
                        });
                    }
                });
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