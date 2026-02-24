<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Admin;
use App\Models\Personnel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /**
     * List accounts the current user can access.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $accounts = collect();
        if ($user instanceof Admin) {
            $query = $user->accounts();
            if (!$request->boolean('include_inactive')) {
                $query->where('is_active', true);
            }
            $accounts = $query->orderBy('name')->get();
        } elseif ($user instanceof Personnel) {
            $accounts = $user->accounts()->where('is_active', true)->orderBy('name')->get();
            
            // TEMPORARY FIX: If personnel has no accounts assigned, assign all active accounts
            // Remove this after properly assigning accounts through admin interface
            if ($accounts->isEmpty()) {
                $allActiveAccounts = \App\Models\Account::where('is_active', true)->orderBy('name')->get();
                if ($allActiveAccounts->isNotEmpty()) {
                    $user->accounts()->sync($allActiveAccounts->pluck('id')->toArray());
                    $accounts = $allActiveAccounts;
                }
            }
        }

        return response()->json([
            'success' => true,
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->code,
                'logo' => $a->getLogoUrl(),
                'is_active' => $a->is_active,
            ]),
        ]);
    }

    /**
     * Create a new account (admin only). New account gets current user assigned.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'logo' => 'nullable|string', // Base64 can be longer than 255
        ]);

        $code = $validated['code'] ?? Str::slug($validated['name']);
        $code = strtoupper(preg_replace('/[^A-Z0-9\-]/', '', $code));
        if (strlen($code) < 2) {
            $code = 'ACC-' . substr(uniqid(), -6);
        }
        if (Account::where('code', $code)->exists()) {
            $code = $code . '-' . substr(uniqid(), -4);
        }

        $account = Account::create([
            'name' => $validated['name'],
            'code' => $code,
            'logo' => null,
            'is_active' => true,
        ]);

        if (!empty($validated['logo']) && str_starts_with($validated['logo'], 'data:')) {
            $path = $this->saveLogoFromBase64($account->id, $validated['logo']);
            if ($path) {
                $account->update(['logo' => $path]);
            }
        }

        $request->user()->accounts()->attach($account->id);

        // Assign new account to all active personnel (bulk insert so it stays fast).
        $personnelIds = Personnel::where('is_active', true)->pluck('id');
        $now = now();
        $rows = $personnelIds->map(fn ($id) => [
            'account_id' => $account->id,
            'user_type' => Personnel::class,
            'user_id' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->toArray();
        if (!empty($rows)) {
            DB::table('account_user')->insertOrIgnore($rows);
        }

        // Clone default account types and COA so new business always has them (bulk inserts = fast).
        $this->cloneDefaultStructureToNewAccount($account);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'code' => $account->code,
                'logo' => $account->fresh()->getLogoUrl(),
                'is_active' => $account->is_active,
            ],
        ], 201);
    }

    /** Built-in default account types (same as AccountTypeSeeder). Used when no template account has data. */
    private static function getBuiltInAccountTypes(): array
    {
        return [
            ['code' => 'ASSETS', 'name' => 'Assets', 'normal_balance' => 'DR', 'category' => 'asset', 'color' => '#28a745', 'icon' => 'FaWallet', 'display_order' => 1],
            ['code' => 'LIABILITIES', 'name' => 'Liabilities', 'normal_balance' => 'CR', 'category' => 'liability', 'color' => '#ffc107', 'icon' => 'FaFileInvoice', 'display_order' => 2],
            ['code' => 'EQUITY', 'name' => 'Equity', 'normal_balance' => 'CR', 'category' => 'equity', 'color' => '#17a2b8', 'icon' => 'FaBalanceScale', 'display_order' => 3],
            ['code' => 'REVENUE', 'name' => 'Revenue', 'normal_balance' => 'CR', 'category' => 'revenue', 'color' => '#007bff', 'icon' => 'FaArrowUp', 'display_order' => 4],
            ['code' => 'COST_OF_SERVICES', 'name' => 'Cost of Services', 'normal_balance' => 'DR', 'category' => 'expense', 'color' => '#dc3545', 'icon' => 'FaArrowDown', 'display_order' => 5],
            ['code' => 'OPERATING_EXPENSES', 'name' => 'Operating Expenses', 'normal_balance' => 'DR', 'category' => 'expense', 'color' => '#dc3545', 'icon' => 'FaArrowDown', 'display_order' => 6],
        ];
    }

    /** Built-in default chart of accounts (same as ChartOfAccountSeeder). */
    private static function getBuiltInChartOfAccounts(): array
    {
        return [
            ['account_code' => '1010', 'account_name' => 'Cash on Hand', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1020', 'account_name' => 'Cash in Bank', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1030', 'account_name' => 'Petty Cash Fund', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1040', 'account_name' => 'Accounts Receivable - Clients', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1050', 'account_name' => 'Commission Receivable', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1060', 'account_name' => 'Advances to Suppliers', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1070', 'account_name' => 'Prepaid Expenses', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1210', 'account_name' => 'Office Equipment', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1220', 'account_name' => 'Furniture & Fixtures', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1230', 'account_name' => 'Computer & IT Equipment', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '1240', 'account_name' => 'Accumulated Depreciation', 'account_type_code' => 'ASSETS', 'normal_balance' => 'CR'],
            ['account_code' => '1250', 'account_name' => 'Software & Licenses', 'account_type_code' => 'ASSETS', 'normal_balance' => 'DR'],
            ['account_code' => '2010', 'account_name' => 'Accounts Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2020', 'account_name' => 'Accrued Expenses', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2030', 'account_name' => 'Taxes Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2040', 'account_name' => 'Withholding Tax Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2050', 'account_name' => 'Advances from Clients', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2060', 'account_name' => 'Commission Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2210', 'account_name' => 'Bank Loan Payable', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '2220', 'account_name' => 'Loans Payable - Officers', 'account_type_code' => 'LIABILITIES', 'normal_balance' => 'CR'],
            ['account_code' => '3010', 'account_name' => "Owner's Capital/Paid-In Capital", 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3020', 'account_name' => 'Additional Paid-In Capital', 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3030', 'account_name' => 'Retained Earnings', 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3040', 'account_name' => 'Current Year Net Income', 'account_type_code' => 'EQUITY', 'normal_balance' => 'CR'],
            ['account_code' => '3050', 'account_name' => "Owner's Drawings", 'account_type_code' => 'EQUITY', 'normal_balance' => 'DR'],
            ['account_code' => '4010', 'account_name' => 'Marketing Consultancy Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4020', 'account_name' => 'Real Estate Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4030', 'account_name' => 'Construction Referral Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4040', 'account_name' => 'Memorial Lot Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4050', 'account_name' => 'Food Business Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4060', 'account_name' => 'Rental/Service Commission Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '4070', 'account_name' => 'Other Service Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '5010', 'account_name' => 'Consultant Fees', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],
            ['account_code' => '5020', 'account_name' => 'Agent Commission Expense', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],
            ['account_code' => '5030', 'account_name' => 'Project-Based Labor', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],
            ['account_code' => '5040', 'account_name' => 'Outsourced Services', 'account_type_code' => 'COST_OF_SERVICES', 'normal_balance' => 'DR'],
            ['account_code' => '6010', 'account_name' => 'Salaries & Wages', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6020', 'account_name' => 'Professional Fees', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6030', 'account_name' => 'Office Supplies Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6040', 'account_name' => 'Transportation & Travel', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6050', 'account_name' => 'Representation Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6110', 'account_name' => 'Advertising & Promotions', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6120', 'account_name' => 'Social Media Marketing', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6130', 'account_name' => 'Printing & Flyers', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6210', 'account_name' => 'Utilities Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6220', 'account_name' => 'Rent Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6230', 'account_name' => 'Communication Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6240', 'account_name' => 'Internet & Software Subscription', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6250', 'account_name' => 'Repairs & Maintenance', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6260', 'account_name' => 'Depreciation Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '6270', 'account_name' => 'Miscellaneous Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '7010', 'account_name' => 'Interest Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '7020', 'account_name' => 'Other Income', 'account_type_code' => 'REVENUE', 'normal_balance' => 'CR'],
            ['account_code' => '7030', 'account_name' => 'Interest Expense', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
            ['account_code' => '7040', 'account_name' => 'Bank Charges', 'account_type_code' => 'OPERATING_EXPENSES', 'normal_balance' => 'DR'],
        ];
    }

    /**
     * Seed account types and COA from built-in defaults. Used when no template account has data.
     */
    private function seedDefaultStructureFromBuiltIn(Account $newAccount): void
    {
        $now = now();
        $accountId = $newAccount->id;

        $typeRows = [];
        foreach (self::getBuiltInAccountTypes() as $t) {
            $typeRows[] = [
                'account_id' => $accountId,
                'code' => $t['code'],
                'name' => $t['name'],
                'normal_balance' => $t['normal_balance'],
                'category' => $t['category'],
                'color' => $t['color'],
                'icon' => $t['icon'],
                'display_order' => $t['display_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        AccountType::insert($typeRows);

        $newTypes = AccountType::where('account_id', $accountId)->orderBy('display_order')->orderBy('id')->get()->keyBy('code');
        $coaRows = [];
        foreach (self::getBuiltInChartOfAccounts() as $row) {
            $type = $newTypes->get($row['account_type_code']);
            if (!$type) {
                continue;
            }
            $coaRows[] = [
                'account_id' => $accountId,
                'account_type_id' => $type->id,
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'normal_balance' => $row['normal_balance'],
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (!empty($coaRows)) {
            ChartOfAccount::insert($coaRows);
        }
    }

    /**
     * Clone account types and chart of accounts from the default (first) business account to a new account.
     * If no template or template has no types/COA, seeds from built-in defaults so every new business always has a COA.
     */
    private function cloneDefaultStructureToNewAccount(Account $newAccount): void
    {
        $templateAccount = Account::orderBy('id')->first();
        $useBuiltIn = true;

        if ($templateAccount && $templateAccount->id !== $newAccount->id) {
            $templateTypes = AccountType::where('account_id', $templateAccount->id)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();
            $templateCoas = ChartOfAccount::where('account_id', $templateAccount->id)->orderBy('account_code')->get();

            if ($templateTypes->isNotEmpty() && $templateCoas->isNotEmpty()) {
                $useBuiltIn = false;
                $now = now();
                $typeRows = $templateTypes->map(fn ($t) => [
                    'account_id' => $newAccount->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'normal_balance' => $t->normal_balance,
                    'category' => $t->category,
                    'color' => $t->color,
                    'icon' => $t->icon,
                    'display_order' => $t->display_order,
                    'is_active' => $t->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray();
                AccountType::insert($typeRows);

                $newTypes = AccountType::where('account_id', $newAccount->id)
                    ->orderBy('display_order')
                    ->orderBy('id')
                    ->get();
                $typeIdMap = [];
                foreach ($templateTypes as $i => $t) {
                    $typeIdMap[$t->id] = $newTypes[$i]->id ?? null;
                }

                $coaRows = [];
                foreach ($templateCoas as $coa) {
                    $newTypeId = $typeIdMap[$coa->account_type_id] ?? null;
                    if ($newTypeId === null) {
                        continue;
                    }
                    $coaRows[] = [
                        'account_id' => $newAccount->id,
                        'account_type_id' => $newTypeId,
                        'account_code' => $coa->account_code,
                        'account_name' => $coa->account_name,
                        'normal_balance' => $coa->normal_balance,
                        'description' => $coa->description,
                        'is_active' => $coa->is_active,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (!empty($coaRows)) {
                    ChartOfAccount::insert($coaRows);
                }
            }
        }

        if ($useBuiltIn) {
            $this->seedDefaultStructureFromBuiltIn($newAccount);
        }
    }

    /**
     * Show single account (must have access).
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $account = Account::where('id', $id)->where('is_active', true)->first();
        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Account not found'], 404);
        }

        $hasAccess = false;
        if ($user instanceof Admin) {
            $hasAccess = $user->accounts()->where('accounts.id', $id)->exists();
        } elseif ($user instanceof Personnel) {
            $hasAccess = $user->accounts()->where('accounts.id', $id)->exists();
        }

        if (!$hasAccess) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return response()->json([
            'success' => true,
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'code' => $account->code,
                'logo' => $account->getLogoUrl(),
                'is_active' => $account->is_active,
            ],
        ]);
    }

    /**
     * Update account (admin only).
     */
    public function update(Request $request, $id): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $account = Account::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('accounts', 'code')->ignore($account->id)],
            'logo' => 'nullable',
            'is_active' => 'sometimes|boolean',
        ]);
        if (array_key_exists('logo', $validated) && $validated['logo'] === null) {
            $oldLogo = $account->logo;
            if ($oldLogo && !str_starts_with($oldLogo, 'data:')) {
                Storage::disk('public')->delete($oldLogo);
            }
        }
        $account->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account updated successfully.',
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'code' => $account->code,
                'logo' => $account->getLogoUrl(),
                'is_active' => $account->is_active,
            ],
        ]);
    }

    /**
     * Delete account (admin only). Permanently deletes the business account and all related data (cascade).
     * The system must retain at least one business account; deletion of the last account is not allowed.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $account = Account::findOrFail($id);

        if (Account::count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'The system must retain at least one business account. Deletion of the only remaining account is not allowed.',
            ], 422);
        }

        // Delete logo file from storage if stored as path
        if ($account->logo && !str_starts_with($account->logo, 'data:')) {
            Storage::disk('public')->delete($account->logo);
        }

        // Delete in correct order to satisfy foreign keys. Multiple tables reference chart_of_accounts (restrict),
        // and chart_of_accounts references account_types (restrict). So: remove dependents of chart_of_accounts first.
        $accountId = $account->id;

        DB::transaction(function () use ($accountId, $account) {
            $coaIds = DB::table('chart_of_accounts')->where('account_id', $accountId)->pluck('id');

            if ($coaIds->isNotEmpty()) {
                DB::table('journal_entry_lines')->whereIn('account_id', $coaIds)->delete();
            }

            if (Schema::hasTable('payments')) {
                DB::table('payments')->where('account_id', $accountId)->delete();
            }
            if (Schema::hasTable('invoices')) {
                DB::table('invoices')->where('account_id', $accountId)->delete();
            }
            if (Schema::hasTable('bills')) {
                DB::table('bills')->where('account_id', $accountId)->delete();
            }

            DB::table('chart_of_accounts')->where('account_id', $accountId)->delete();
            DB::table('account_types')->where('account_id', $accountId)->delete();

            $account->delete();
        });

        return response()->json(['success' => true, 'message' => 'Account deleted successfully']);
    }

    /**
     * Save base64 logo to disk and return storage path. Avoids storing large blobs in DB (max_allowed_packet).
     */
    private function saveLogoFromBase64(int $accountId, string $dataUrl): ?string
    {
        if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $dataUrl, $m)) {
            return null;
        }
        $ext = strtolower($m[1]);
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return null;
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            return null;
        }
        $filename = 'account-' . $accountId . '-' . uniqid() . '.' . $ext;
        $path = 'account-logos/' . $filename;
        Storage::disk('public')->put($path, $binary);
        return $path;
    }

    /**
     * Upload logo for account. Saves file to disk and stores path in DB (no large base64 in MySQL).
     */
    public function uploadLogo(Request $request, $id): JsonResponse
    {
        if (!$request->user() instanceof Admin) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $account = Account::findOrFail($id);

        $validated = $request->validate([
            'logo' => 'required|string', // Base64 data URL
        ]);

        $path = $this->saveLogoFromBase64((int) $id, $validated['logo']);
        if (!$path) {
            return response()->json(['success' => false, 'message' => 'Invalid image data'], 422);
        }

        // Delete previous logo file if it was a path (not base64)
        $oldLogo = $account->logo;
        if ($oldLogo && !str_starts_with($oldLogo, 'data:')) {
            Storage::disk('public')->delete($oldLogo);
        }

        $account->update(['logo' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Logo uploaded successfully.',
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'code' => $account->code,
                'logo' => $account->getLogoUrl(),
                'is_active' => $account->is_active,
            ],
        ]);
    }
}
