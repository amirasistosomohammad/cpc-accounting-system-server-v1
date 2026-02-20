<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Account extends Model
{
    protected $fillable = [
        'name',
        'code',
        'logo',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Return logo as full URL when stored as path; otherwise return as-is (base64 or null).
     */
    public function getLogoUrl(): ?string
    {
        $logo = $this->logo;
        if ($logo === null || $logo === '') {
            return null;
        }
        if (str_starts_with($logo, 'data:')) {
            return $logo;
        }
        return Storage::disk('public')->exists($logo)
            ? asset('storage/' . $logo)
            : null;
    }

    public function admins()
    {
        return $this->morphedByMany(Admin::class, 'user', 'account_user')
            ->withTimestamps();
    }

    public function personnel()
    {
        return $this->morphedByMany(Personnel::class, 'user', 'account_user')
            ->withTimestamps();
    }
}
