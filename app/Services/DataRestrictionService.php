<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Models\Employee;

class DataRestrictionService
{
    public function filterArray(array $data)
    {
        $user = Auth::user();
        if (!$user || empty($user->employee_id)) {
            return [];
        }

        $roleIds = DB::connection('mysql')
            ->table('role_user')
            ->where('user_id', $user->employee_id)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $roles = Role::whereIn('id', $roleIds)->get();

        if ($roles->contains(fn($r) => strtolower($r->name) === 'super admin')) {
            return $data;
        }

        $nikList = [];
        foreach ($data as $item) {
            $nik = $item['employee_id'] 
                ?? $item['user_id'] 
                ?? $item['nik'] 
                ?? $item['sender_id'] 
                ?? null;

            if ($nik) {
                $nikList[] = (string)$nik;
            }
        }
        
        $nikList = array_unique($nikList);

        if (empty($nikList)) {
            return []; 
        }

        $allowedNiks = Employee::scoped()
            ->whereIn('employee_id', $nikList)
            ->pluck('employee_id')
            ->map(function($id) {
                return (string)$id;
            })
            ->toArray();

        return array_filter($data, function ($item) use ($allowedNiks) {
            $nik = $item['employee_id'] 
                ?? $item['user_id'] 
                ?? $item['nik'] 
                ?? $item['sender_id'] 
                ?? null;
            
            if (!$nik) return false;

            return in_array((string)$nik, $allowedNiks);
        });
    }
}