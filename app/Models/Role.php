<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 
        'scope_bu', 
        'scope_company', 
        'scope_location'
    ];

    public function users()
    {
        $relation = $this->belongsToMany(Employee::class, 'role_user', 'role_id', 'user_id');
        $relation->getQuery()->from('role_user'); 
        return $relation;
    }
}