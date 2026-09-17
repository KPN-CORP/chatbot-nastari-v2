<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\ScopedByRole; 

class Employee extends Model
{
    use ScopedByRole; 

    protected $connection = 'kpncorp';
    protected $table = 'employees';
    protected $guarded = ['id'];

    public function roles()
    {
        return $this->belongsToMany(
            Role::class, 
            'role_user',
            'user_id',                 
            'role_id'                     
        );
    }
}