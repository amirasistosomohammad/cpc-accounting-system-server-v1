<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'username',
        'password',
        'name',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Accounts this admin can access.
     */
    public function accounts()
    {
        return $this->morphToMany(Account::class, 'user', 'account_user')
            ->withTimestamps();
    }
}
