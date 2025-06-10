<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'type', 'title', 'message', 'data', 'read', 'timestamp',
        'priority', 'employee_id', 'department',
    ];

    protected $casts = [
        'data' => 'array',
        'read' => 'boolean',
        'timestamp' => 'datetime',
    ];
}
