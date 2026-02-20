<?php

namespace App\Models;

use App\Models\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use BelongsToAccount;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'account_id',
        'account_code',
        'account_name',
        'account_type_id',
        'normal_balance',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Append account_type code and category to JSON (category drives expense/income dropdowns regardless of type name)
     */
    protected $appends = ['account_type', 'account_type_category'];

    /**
     * Account type code for API (from relation or legacy DB column).
     * Use getRelationValue() to avoid "Undefined property $accountType" when model is serialized.
     * Defensive: never call ->code on a string (table may still have enum column account_type).
     */
    public function getAccountTypeAttribute(): ?string
    {
        $relation = $this->getRelationValue('accountType');
        if (is_object($relation) && isset($relation->code)) {
            return $relation->code;
        }
        // Legacy: DB column account_type (string/enum) if relation not loaded or table not migrated
        $raw = $this->getRawOriginal('account_type');
        return is_string($raw) ? $raw : null;
    }

    /**
     * Category of the account type (asset, liability, equity, revenue, expense). Used for expense/income filters.
     */
    public function getAccountTypeCategoryAttribute(): ?string
    {
        $relation = $this->getRelationValue('accountType');
        return (is_object($relation) && isset($relation->category)) ? $relation->category : null;
    }

    /**
     * Get the account type for this chart of account
     */
    public function accountType()
    {
        return $this->belongsTo(AccountType::class);
    }

    /**
     * Get all journal entry lines for this account
     */
    public function journalEntryLines()
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    /**
     * Calculate current balance for this account
     */
    public function getBalanceAttribute()
    {
        $debits = $this->journalEntryLines()->sum('debit_amount');
        $credits = $this->journalEntryLines()->sum('credit_amount');

        if ($this->normal_balance === 'DR') {
            return $debits - $credits;
        } else {
            return $credits - $debits;
        }
    }
}


