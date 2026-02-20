<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\AuthorizationCode;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\AuthorizationCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    /**
     * Get all clients
     */
    public function index(Request $request): JsonResponse
    {
        $query = Client::with('invoices');

        // Search filter
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Active filter
        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }

        $clients = $query->orderBy('name')->get();

        // Calculate total receivable and append footprint for view modal
        $clients->each(function ($client) {
            $client->total_receivable = $client->total_receivable;
            $client->created_by_name = ActivityLogService::resolveNameFromTypeId($client->created_by_type, $client->created_by_id);
            $client->updated_by_name = ActivityLogService::resolveNameFromTypeId($client->updated_by_type, $client->updated_by_id);
        });

        return response()->json($clients);
    }

    /**
     * Get a single client
     */
    public function show($id): JsonResponse
    {
        $client = Client::with('invoices.incomeAccount')->findOrFail($id);
        $client->total_receivable = $client->total_receivable;
        $client->created_by_name = ActivityLogService::resolveNameFromTypeId($client->created_by_type, $client->created_by_id);
        $client->updated_by_name = ActivityLogService::resolveNameFromTypeId($client->updated_by_type, $client->updated_by_id);

        return response()->json($client);
    }

    /**
     * Create a new client
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[+()\\-\\s0-9]+$/'],
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'profile' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $actor = ActivityLogService::getUserTypeAndId($request->user());
        $validated['created_by_type'] = $actor['user_type'];
        $validated['created_by_id'] = $actor['user_id'];

        $client = Client::create($validated);

        ActivityLogService::log('created', $request->user(), 'client', $client->id, null, $client->toArray(), null, null, $request);

        return response()->json($client, 201);
    }

    /**
     * Update a client
     */
    public function update(Request $request, $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $oldValues = $client->toArray();

        $validated = $request->validate([
            'name' => 'required|string|min:2|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[+()\\-\\s0-9]+$/'],
            'address' => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'profile' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $actor = ActivityLogService::getUserTypeAndId($request->user());
        $validated['updated_by_type'] = $actor['user_type'];
        $validated['updated_by_id'] = $actor['user_id'];

        $client->update($validated);

        ActivityLogService::log('updated', $request->user(), 'client', $client->id, $oldValues, $client->fresh()->toArray(), null, null, $request);

        return response()->json($client);
    }

    /**
     * Delete a client. Personnel must provide a valid authorization_code for delete_client.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $user = $request->user();
        $authCodeId = null;

        if (!$user instanceof Admin) {
            $code = $request->input('authorization_code');
            if (!$code) {
                throw ValidationException::withMessages(['authorization_code' => ['This action requires an authorization code from your admin.']]);
            }
            $codeModel = AuthorizationCodeService::validateAndUse($code, 'delete_client', $user, Client::class, $client->id);
            $authCodeId = $codeModel->id;
        }

        $clientData = $client->toArray();

        if ($client->invoices()->exists()) {
            $actor = ActivityLogService::getUserTypeAndId($user);
            $client->update([
                'is_active' => false,
                'updated_by_type' => $actor['user_type'],
                'updated_by_id' => $actor['user_id'],
            ]);
            ActivityLogService::log('deactivated', $user, 'client', $client->id, $clientData, $client->fresh()->toArray(), $authCodeId, $request->input('remarks'), $request);
            return response()->json([
                'message' => 'Client deactivated (has invoices)',
                'client' => $client->fresh(),
            ]);
        }

        $client->delete();
        ActivityLogService::log('deleted', $user, 'client', (int) $id, $clientData, null, $authCodeId, $request->input('remarks'), $request);

        return response()->json(['message' => 'Client deleted successfully']);
    }
}
