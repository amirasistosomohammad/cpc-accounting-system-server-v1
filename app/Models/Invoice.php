<?php

namespace App\Models;

use App\Models\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'invoice_number',
        'client_id',
        'invoice_date',
        'due_date',
        'income_account_id',
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
        'invoice_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    /**
     * Get the client for this invoice
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the income account
     */
    public function incomeAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_account_id');
    }

    /**
     * Get the journal entry
     */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get all payments for this invoice
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Generate unique invoice number
     */
    public static function generateInvoiceNumber()
    {
        $date = now()->format('Ymd');
        $accountId = request()->attributes->get('current_account_id');
        $query = self::query();
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        $lastInvoice = $query->whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return 'INV-' . $date . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}


