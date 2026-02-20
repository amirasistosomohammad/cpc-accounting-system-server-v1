<?php

namespace App\Models;

use App\Models\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'name',
        'email',
        'phone',
        'address',
        'contact_person',
        'notes',
        'profile',
        'is_active',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'profile' => 'array',
    ];

    /**
     * Get all invoices for this client
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Calculate total accounts receivable for this client
     */
    public function getTotalReceivableAttribute()
    {
        return (float) $this->invoices()->sum('balance') ?? 0.00;
    }
}
