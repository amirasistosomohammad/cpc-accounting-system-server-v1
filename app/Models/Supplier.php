<?php

namespace App\Models;

use App\Models\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
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
        'is_active',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get all bills for this supplier
     */
    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * Calculate total accounts payable for this supplier
     */
    public function getTotalPayableAttribute()
    {
        return (float) $this->bills()->sum('balance') ?? 0.00;
    }
}

