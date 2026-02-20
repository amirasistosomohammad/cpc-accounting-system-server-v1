<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuthorizationCode extends Model
{
    protected $table = 'authorization_codes';

    protected $fillable = [
        'code',
        'admin_type',
        'admin_id',
        'for_action',
        'description',
        'subject_type',
        'subject_id',
        'expires_at',
        'is_active',
        'used_at',
        'used_by_type',
        'used_by_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'is_active' => 'boolean',
    ];


    public static function generateCode(string $forAction, $admin, ?string $subjectType = null, ?int $subjectId = null, int $validMinutes = 10): self
    {
        $code = strtoupper(Str::random(6));
        while (self::where('code', $code)->where('expires_at', '>', now())->exists()) {
            $code = strtoupper(Str::random(6));
        }

        $adminType = $admin instanceof \App\Models\Admin ? 'admin' : 'personnel';
        $adminId = $admin->id;

        return self::create([
            'code' => $code,
            'admin_type' => $adminType,
            'admin_id' => $adminId,
            'for_action' => $forAction,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'expires_at' => now()->addMinutes($validMinutes),
            'is_active' => true,
        ]);
    }

    public function isValid(): bool
    {
        // If expires_at is null, treat it as "no expiration".
        $notExpired = !$this->expires_at || $this->expires_at->isFuture();

        // Manual codes created via Settings (`for_action = 'manual'`) are
        // intended to be reusable until they expire or are deactivated.
        if ($this->for_action === 'manual') {
            return ($this->is_active !== false) && $notExpired;
        }

        // Auto-generated one‑time codes remain single‑use.
        return ($this->is_active !== false) && !$this->used_at && $notExpired;
    }

    /** @param string $userType 'admin' or 'personnel' (stored as 1 or 2 in DB) */
    public function markUsed(string $userType, int $userId): void
    {
        $typeId = $userType === 'admin' ? 1 : ($userType === 'personnel' ? 2 : 0);
        $this->update([
            'used_at' => now(),
            'used_by_type' => $typeId,
            'used_by_id' => $userId,
        ]);
    }
}
