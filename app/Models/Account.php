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
     * Served via /api/account-logo/{filename} so URL is always same-origin HTTPS (like DATravelApp image handling).
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
        $path = $logo;
        $filename = basename($path);
        if ($filename === '' || !Storage::disk('public')->exists($path)) {
            return null;
        }
        // Use request host when available so production returns HTTPS (avoids mixed content when client is on HTTPS).
        $baseUrl = null;
        if (app()->has('request') && request()) {
            $baseUrl = request()->getSchemeAndHttpHost();
        }
        if (!$baseUrl || $baseUrl === 'http://' || $baseUrl === 'https://') {
            $baseUrl = rtrim(config('app.url'), '/');
        }
        return $baseUrl . '/api/account-logo/' . $filename;
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
