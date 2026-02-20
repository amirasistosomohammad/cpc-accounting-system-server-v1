<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountType extends Model
{
    /** Categories used for filtering (expense dropdown, income dropdown, reports). Not tied to code/name. */
    public const CATEGORY_ASSET = 'asset';
    public const CATEGORY_LIABILITY = 'liability';
    public const CATEGORY_EQUITY = 'equity';
    public const CATEGORY_REVENUE = 'revenue';
    public const CATEGORY_EXPENSE = 'expense';

    public const CATEGORIES = [
        self::CATEGORY_ASSET,
        self::CATEGORY_LIABILITY,
        self::CATEGORY_EQUITY,
        self::CATEGORY_REVENUE,
        self::CATEGORY_EXPENSE,
    ];

    protected $fillable = [
        'account_id',
        'code',
        'name',
        'normal_balance',
        'category',
        'color',
        'icon',
        'display_order',
        'is_active',
    ];

    /**
     * Business account this type belongs to
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    /**
     * Get all chart of accounts of this type
     */
    public function chartOfAccounts()
    {
        return $this->hasMany(ChartOfAccount::class);
    }
}
