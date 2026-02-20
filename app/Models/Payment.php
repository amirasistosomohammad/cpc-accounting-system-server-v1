<?php

namespace App\Models;

use App\Models\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'payment_number',
        'payment_type',
        'invoice_id',
        'bill_id',
        'payment_date',
        'cash_account_id',
        'amount',
        'payment_method',
        'reference_number',
        'notes',
        'journal_entry_id',
        'created_by_type',
        'created_by_id',
        'voided_at',
        'voided_by_type',
        'voided_by_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    /**
     * Get the invoice (for receipts)
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the bill (for payments)
     */
    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * Get the cash account
     */
    public function cashAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    /**
     * Get the journal entry
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Generate unique payment number
     */
    public static function generatePaymentNumber($type = 'receipt')
    {
        $prefix = $type === 'receipt' ? 'RCP' : 'PAY';
        $date = now()->format('Ymd');
        $accountId = request()->attributes->get('current_account_id');
        $query = self::query();
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        $lastPayment = $query->where('payment_type', $type)
            ->whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int) substr($lastPayment->payment_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . '-' . $date . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}


