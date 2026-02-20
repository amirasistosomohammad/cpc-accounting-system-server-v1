<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\TimeLog;
use Illuminate\Http\Request;

class TimeLogService
{
    /**
     * Record time-in when personnel logs in (automatic). Creates or updates today's time log.
     */
    public static function recordLogin(Personnel $user, Request $request): void
    {
        $today = now()->toDateString();
        $now = now()->format('H:i:s');
        $userName = ActivityLogService::resolveActorName($user);

        $log = TimeLog::firstOrCreate(
            [
                'user_type' => 'personnel',
                'user_id' => $user->id,
                'log_date' => $today,
            ],
            [
                'user_name' => $userName,
                'time_in' => $now,
                'source' => 'login',
                'ip_address' => $request->ip(),
            ]
        );

        if (!$log->time_in) {
            $log->update([
                'time_in' => $now,
                'user_name' => $userName,
                'source' => 'login',
                'ip_address' => $request->ip(),
            ]);
        }
    }

    /**
     * Record time-out when personnel logs out (automatic). Updates today's time log.
     */
    public static function recordLogout(Personnel $user, Request $request): void
    {
        $today = now()->toDateString();
        $now = now()->format('H:i:s');

        $log = TimeLog::where('user_type', 'personnel')
            ->where('user_id', $user->id)
            ->where('log_date', $today)
            ->first();

        if ($log) {
            $log->update(['time_out' => $now]);
        }
    }
}
