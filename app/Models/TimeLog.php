<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeLog extends Model
{
    protected $table = 'time_logs';

    protected $fillable = [
        'user_type',
        'user_id',
        'user_name',
        'log_date',
        'time_in',
        'time_out',
        'source',
        'ip_address',
    ];

    protected $casts = [
        'log_date' => 'date',
    ];
}
