<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\ScopedByRole;

class DailyChatLog extends Model
{
    use ScopedByRole;
    protected $guarded = ['id'];
    protected $casts = [
        'date' => 'date',
        'messages' => 'array'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}