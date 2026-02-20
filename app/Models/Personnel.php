<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class Personnel extends Model
{
    use HasApiTokens;

    protected $table = 'personnel';

    protected $fillable = [
        'username',
        'password',
        'first_name',
        'last_name',
        'phone',
        'avatar_path',
        'is_active',
        'sidebar_access',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'sidebar_access' => 'array',
        ];
    }

    /**
     * Accounts this personnel can access.
     */
    public function accounts()
    {
        return $this->morphToMany(Account::class, 'user', 'account_user')
            ->withTimestamps();
    }
}
