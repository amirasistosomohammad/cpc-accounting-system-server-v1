<?php

namespace App\Models;

use App\Models\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'bill_number',
        'supplier_id',
        'bill_date',
        'due_date',
        'expense_account_id',
        'total_amount',
        'paid_amount',
        'balance',
        'description',
        'status',
        'journal_entry_id',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    /**
     * Get the supplier for this bill
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the expense account
     */
    public function expenseAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    /**
     * Get the journal entry
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get all payments for this bill
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Generate unique bill number
     */
    public static function generateBillNumber()
    {
        $date = now()->format('Ymd');
        $accountId = request()->attributes->get('current_account_id');
        $query = self::query();
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        $lastBill = $query->whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        if ($lastBill) {
            $lastNumber = (int) substr($lastBill->bill_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return 'BILL-' . $date . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}


