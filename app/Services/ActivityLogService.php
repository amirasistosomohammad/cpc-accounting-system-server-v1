<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Personnel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogService
{
    public static function resolveActorName($user): ?string
    {
        if (!$user) {
            return null;
        }
        if ($user instanceof Admin) {
            return $user->name ?? $user->username ?? 'Admin#' . $user->id;
        }
        if ($user instanceof Personnel) {
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            return $name ?: ($user->username ?? 'Personnel#' . $user->id);
        }
        return 'Unknown';
    }

    public static function getUserTypeAndId($user): array
    {
        if (!$user) {
            return ['user_type' => null, 'user_id' => null];
        }
        if ($user instanceof Admin) {
            return ['user_type' => 'admin', 'user_id' => $user->id];
        }
        if ($user instanceof Personnel) {
            return ['user_type' => 'personnel', 'user_id' => $user->id];
        }
        return ['user_type' => null, 'user_id' => null];
    }

    /** Resolve display name from user_type + user_id for footprint display */
    public static function resolveNameFromTypeId(?string $userType, ?int $userId): ?string
    {
        if (!$userType || !$userId) {
            return null;
        }
        if ($userType === 'admin') {
            $admin = Admin::find($userId);
            return $admin ? ($admin->name ?? $admin->username) : null;
        }
        if ($userType === 'personnel') {
            $p = Personnel::find($userId);
            return $p ? trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')) : null;
        }
        return null;
    }

    public static function log(
        string $action,
        $user,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $authorizationCodeId = null,
        ?string $remarks = null,
        ?Request $request = null
    ): ?ActivityLog {
        try {
            $req = $request ?? request();
            $data = array_merge(self::getUserTypeAndId($user), [
                'user_name' => self::resolveActorName($user),
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'authorization_code_id' => $authorizationCodeId,
                'ip_address' => $req ? $req->ip() : null,
                'user_agent' => $req && $req->userAgent() ? substr($req->userAgent(), 0, 500) : null,
                'remarks' => $remarks,
            ]);
            return ActivityLog::create($data);
        } catch (\Throwable $e) {
            Log::warning('ActivityLogService::log failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function logLogin($user, ?Request $request = null): void
    {
        self::log('login', $user, null, null, null, null, null, null, $request);
    }

    public static function logLogout($user, ?Request $request = null): void
    {
        self::log('logout', $user, null, null, null, null, null, null, $request);
    }
}
