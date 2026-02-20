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

        // Assign the new business account to all active personnel so it appears in their topbar dropdown
        Personnel::where('is_active', true)->each(function (Personnel $p) use ($account) {
            $p->accounts()->syncWithoutDetaching([$account->id]);
        });

        // Clone default account types and chart of accounts from the first business account (same as seeder)
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

    /**
     * Clone account types and chart of accounts from the default (first) business account to a new account.
     * Data is independent per business account; admin can edit/delete/modify COA and types per account anytime.
     */
    private function cloneDefaultStructureToNewAccount(Account $newAccount): void
    {
        $templateAccount = Account::orderBy('id')->first();
        if (!$templateAccount || $templateAccount->id === $newAccount->id) {
            return;
        }

        $templateTypes = AccountType::where('account_id', $templateAccount->id)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        if ($templateTypes->isEmpty()) {
            return;
        }

        $typeIdMap = [];
        foreach ($templateTypes as $t) {
            $newType = AccountType::create([
                'account_id' => $newAccount->id,
                'code' => $t->code,
                'name' => $t->name,
                'normal_balance' => $t->normal_balance,
                'category' => $t->category,
                'color' => $t->color,
                'icon' => $t->icon,
                'display_order' => $t->display_order,
                'is_active' => $t->is_active,
            ]);
            $typeIdMap[$t->id] = $newType->id;
        }

        $templateCoas = ChartOfAccount::where('account_id', $templateAccount->id)->orderBy('account_code')->get();
        foreach ($templateCoas as $coa) {
            $newTypeId = $typeIdMap[$coa->account_type_id] ?? null;
            if ($newTypeId === null) {
                continue;
            }
            ChartOfAccount::create([
                'account_id' => $newAccount->id,
                'account_type_id' => $newTypeId,
                'account_code' => $coa->account_code,
                'account_name' => $coa->account_name,
                'normal_balance' => $coa->normal_balance,
                'description' => $coa->description,
                'is_active' => $coa->is_active,
            ]);
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
