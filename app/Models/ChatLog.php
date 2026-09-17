<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'employee_name',
        'business_unit',
        'phone',
        'sender',
        'message',
        'created_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}