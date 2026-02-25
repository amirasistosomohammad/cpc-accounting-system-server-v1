<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\ActivityLogService;
use App\Services\AuthorizationCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JournalEntryController extends Controller
{
    /**
     * Get all journal entries
     */
    public function index(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');

        $query = JournalEntry::with('lines.account.accountType');

        if ($accountId !== null && $accountId !== '') {
            $query->where('account_id', $accountId);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        // Search by entry number or description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        $entries = $query->orderBy('entry_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 15));

        // Append footprint and source_document (lock edit/delete when JE is from invoice, payment, or bill)
        $entries->getCollection()->transform(function ($entry) {
            $entry->created_by_name = ActivityLogService::resolveNameFromTypeId($entry->created_by_type, $entry->created_by);
            $entry->updated_by_name = ActivityLogService::resolveNameFromTypeId($entry->updated_by_type, $entry->updated_by_id);
            $entry->source_document = JournalEntry::getSourceDocument((int) $entry->id);
            return $entry;
        });

        return response()->json($entries);
    }

    /**
     * Get a single journal entry
     */
    public function show($id): JsonResponse
    {
        $entry = JournalEntry::with('lines.account.accountType')->findOrFail($id);
        $entry->created_by_name = ActivityLogService::resolveNameFromTypeId($entry->created_by_type, $entry->created_by);
        $entry->updated_by_name = ActivityLogService::resolveNameFromTypeId($entry->updated_by_type, $entry->updated_by_id);
        $entry->source_document = JournalEntry::getSourceDocument((int) $entry->id);

        return response()->json($entry);
    }

    /**
     * Create a new journal entry
     */
    public function store(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('current_account_id');
        if ($accountId === null || $accountId === '') {
            return response()->json([
                'message' => 'Account context required. Please select a business account.',
            ], 422);
        }
        $validated = $request->validate([
            'entry_date' => 'required|date',
            'description' => 'required|string',
            'reference_number' => 'nullable|string|max:255',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
            'lines.*.debit_amount' => 'required_without:lines.*.credit_amount|numeric|min:0',
            'lines.*.credit_amount' => 'required_without:lines.*.debit_amount|numeric|min:0',
            'lines.*.description' => 'nullable|string',
        ]);

        // Validate that each line has either debit or credit, but not both
        foreach ($validated['lines'] as $index => $line) {
            if (($line['debit_amount'] > 0 && $line['credit_amount'] > 0) ||
                ($line['debit_amount'] == 0 && $line['credit_amount'] == 0)
            ) {
                return response()->json([
                    'message' => "Line " . ($index + 1) . " must have either debit or credit, but not both and not neither."
                ], 422);
            }
        }

        // Calculate totals
        $totalDebit = collect($validated['lines'])->sum('debit_amount');
        $totalCredit = collect($validated['lines'])->sum('credit_amount');

        // Validate that debits equal credits
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json([
                'message' => 'Total debits must equal total credits.',
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'difference' => abs($totalDebit - $totalCredit)
            ], 422);
        }

        try {
            DB::beginTransaction();

            $actor = ActivityLogService::getUserTypeAndId($request->user());
            // Create journal entry
            $entry = JournalEntry::create([
                'account_id' => $accountId,
                'entry_number' => JournalEntry::generateEntryNumber(),
                'entry_date' => $validated['entry_date'],
                'description' => $validated['description'],
                'reference_number' => $validated['reference_number'] ?? null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'created_by' => $actor['user_id'],
                'created_by_type' => $actor['user_type'],
            ]);

            // Create journal entry lines
            foreach ($validated['lines'] as $lineData) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $lineData['account_id'],
                    'debit_amount' => $lineData['debit_amount'] ?? 0,
                    'credit_amount' => $lineData['credit_amount'] ?? 0,
                    'description' => $lineData['description'] ?? null,
                ]);
            }

            DB::commit();

            ActivityLogService::log('created', $request->user(), JournalEntry::class, $entry->id, null, $entry->fresh()->toArray(), null, null, $request);
            // Load relationships
            $entry->load('lines.account.accountType');

            return response()->json($entry, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Journal Entry Creation Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create journal entry.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a journal entry
     */
    public function update(Request $request, $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);

        $source = JournalEntry::getSourceDocument((int) $entry->id);
        if ($source !== null) {
            return response()->json([
                'message' => 'This journal entry was created from ' . $source['type'] . ' ' . $source['reference'] . '. ' . $source['edit_hint'],
                'source_document' => $source,
            ], 422);
        }

        $oldValues = $entry->toArray();
        $accountId = $request->attributes->get('current_account_id');
        $validated = $request->validate([
            'entry_date' => 'required|date',
            'description' => 'required|string',
            'reference_number' => 'nullable|string|max:255',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_id', $accountId)],
            'lines.*.debit_amount' => 'required_without:lines.*.credit_amount|numeric|min:0',
            'lines.*.credit_amount' => 'required_without:lines.*.debit_amount|numeric|min:0',
            'lines.*.description' => 'nullable|string',
        ]);

        // Validate that each line has either debit or credit, but not both
        foreach ($validated['lines'] as $index => $line) {
            if (($line['debit_amount'] > 0 && $line['credit_amount'] > 0) ||
                ($line['debit_amount'] == 0 && $line['credit_amount'] == 0)
            ) {
                return response()->json([
                    'message' => "Line " . ($index + 1) . " must have either debit or credit, but not both and not neither."
                ], 422);
            }
        }

        // Calculate totals
        $totalDebit = collect($validated['lines'])->sum('debit_amount');
        $totalCredit = collect($validated['lines'])->sum('credit_amount');

        // Validate that debits equal credits
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json([
                'message' => 'Total debits must equal total credits.',
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'difference' => abs($totalDebit - $totalCredit)
            ], 422);
        }

        try {
            DB::beginTransaction();

            $actor = ActivityLogService::getUserTypeAndId($request->user());
            // Update journal entry
            $entry->update([
                'entry_date' => $validated['entry_date'],
                'description' => $validated['description'],
                'reference_number' => $validated['reference_number'] ?? null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'updated_by_type' => $actor['user_type'],
                'updated_by_id' => $actor['user_id'],
            ]);

            // Delete existing lines
            $entry->lines()->delete();

            // Create new journal entry lines
            foreach ($validated['lines'] as $lineData) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $lineData['account_id'],
                    'debit_amount' => $lineData['debit_amount'] ?? 0,
                    'credit_amount' => $lineData['credit_amount'] ?? 0,
                    'description' => $lineData['description'] ?? null,
                ]);
            }

            DB::commit();

            // Load relationships
            $entry->load('lines.account.accountType');

            return response()->json($entry);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Journal Entry Update Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update journal entry.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a journal entry. Only personnel must provide an authorization code; admin does not.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);

        $source = JournalEntry::getSourceDocument((int) $entry->id);
        if ($source !== null) {
            return response()->json([
                'message' => 'This journal entry was created from ' . $source['type'] . ' ' . $source['reference'] . '. ' . $source['edit_hint'],
                'source_document' => $source,
            ], 422);
        }

        $user = $request->user();
        $authCodeId = null;

        if (!$user instanceof Admin) {
            $code = $request->input('authorization_code');
            if (!$code) {
                throw ValidationException::withMessages(['authorization_code' => ['This action requires an authorization code from your admin.']]);
            }
            $codeModel = AuthorizationCodeService::validateAndUse($code, 'delete_journal_entry', $user, JournalEntry::class, $entry->id);
            $authCodeId = $codeModel->id;
        }

        try {
            DB::beginTransaction();
            $oldValues = $entry->toArray();

            // Delete lines first (cascade should handle this, but being explicit)
            $entry->lines()->delete();

            // Delete entry
            $entry->delete();

            DB::commit();

            ActivityLogService::log('deleted', $user, JournalEntry::class, (int) $id, $oldValues, null, $authCodeId, $request->input('remarks'), $request);
            return response()->json(['message' => 'Journal entry deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Journal Entry Deletion Failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete journal entry.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
