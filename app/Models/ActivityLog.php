<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [
        'user_type',
        'user_id',
        'user_name',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'authorization_code_id',
        'ip_address',
        'user_agent',
        'remarks',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function authorizationCode(): BelongsTo
    {
        return $this->belongsTo(AuthorizationCode::class);
    }
}
