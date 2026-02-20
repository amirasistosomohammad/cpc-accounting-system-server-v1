<?php

namespace App\Models;

use App\Models\Traits\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'entry_number',
        'entry_date',
        'description',
        'reference_number',
        'total_debit',
        'total_credit',
        'created_by',
        'created_by_type',
        'updated_by_type',
        'updated_by_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
    ];

    /**
     * Get all lines for this journal entry
     */
    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * Get the source document (invoice, payment, or bill) that created this journal entry, if any.
     * Used to lock JE edit/delete when created from Clients/AR, Cash & Bank, or Suppliers/AP.
     *
     * @return array|null ['type' => 'invoice'|'payment'|'bill', 'reference' => string, 'id' => int, 'edit_hint' => string] or null
     */
    public static function getSourceDocument(int $journalEntryId): ?array
    {
        $invoice = \App\Models\Invoice::where('journal_entry_id', $journalEntryId)->first();
        if ($invoice) {
            return [
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'id' => $invoice->id,
                'edit_hint' => 'Edit or void from Clients / AR (Invoices tab).',
            ];
        }

        $payment = \App\Models\Payment::where('journal_entry_id', $journalEntryId)->first();
        if ($payment) {
            return [
                'type' => 'payment',
                'reference' => $payment->payment_number,
                'id' => $payment->id,
                'edit_hint' => 'Void from Clients / AR or Cash & Bank.',
            ];
        }

        $bill = \App\Models\Bill::where('journal_entry_id', $journalEntryId)->first();
        if ($bill) {
            return [
                'type' => 'bill',
                'reference' => $bill->bill_number,
                'id' => $bill->id,
                'edit_hint' => 'Edit or void from Suppliers / AP.',
            ];
        }

        return null;
    }

    /**
     * Generate unique entry number
     */
    public static function generateEntryNumber()
    {
        $date = now()->format('Ymd');
        $accountId = request()->attributes->get('current_account_id');
        $query = self::query();
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        $lastEntry = $query->whereDate('created_at', today())
            ->orderBy('id', 'desc')
            ->first();

        if ($lastEntry) {
            $lastNumber = (int) substr($lastEntry->entry_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return 'JE-' . $date . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
