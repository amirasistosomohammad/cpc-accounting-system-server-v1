<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\TimeLog;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimeLogController extends Controller
{
    /**
     * List time logs (admin only). Filters: user_type, user_id, date_from, date_to
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = TimeLog::query()->orderBy('log_date', 'desc')->orderBy('updated_at', 'desc');

        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('log_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('log_date', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $items = $query->paginate($perPage);

        return response()->json($items);
    }

    /**
     * Clock in (personnel only). Creates/updates today's time log with time_in.
     */
    public function timeIn(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof Admin) {
            return response()->json(['message' => 'Only personnel can clock in.'], 403);
        }

        $userType = 'personnel';
        $userId = $user->id;
        $userName = ActivityLogService::resolveActorName($user);
        $today = now()->toDateString();
        $now = now()->format('H:i:s');

        $log = TimeLog::firstOrCreate(
            [
                'user_type' => $userType,
                'user_id' => $userId,
                'log_date' => $today,
            ],
            [
                'user_name' => $userName,
                'time_in' => $now,
                'source' => 'manual',
                'ip_address' => $request->ip(),
            ]
        );

        if (!$log->time_in) {
            $log->update(['time_in' => $now, 'user_name' => $userName, 'ip_address' => $request->ip()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Clocked in successfully',
            'time_log' => $log->fresh(),
        ]);
    }

    /**
     * Clock out (personnel only). Updates today's time log with time_out.
     */
    public function timeOut(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof Admin) {
            return response()->json(['message' => 'Only personnel can clock out.'], 403);
        }

        $userType = 'personnel';
        $userId = $user->id;
        $today = now()->toDateString();
        $now = now()->format('H:i:s');

        $log = TimeLog::where('user_type', $userType)
            ->where('user_id', $userId)
            ->where('log_date', $today)
            ->first();

        if (!$log) {
            return response()->json(['message' => 'No clock-in found for today. Please clock in first.'], 422);
        }

        $log->update(['time_out' => $now]);

        return response()->json([
            'success' => true,
            'message' => 'Clocked out successfully',
            'time_log' => $log->fresh(),
        ]);
    }

    /**
     * Get current user's today time log (personnel) or summary for admin.
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $userType = $user instanceof Admin ? 'admin' : 'personnel';
        $userId = $user->id;
        $today = now()->toDateString();

        $log = TimeLog::where('user_type', $userType)
            ->where('user_id', $userId)
            ->where('log_date', $today)
            ->first();

        return response()->json([
            'time_log' => $log,
            'today' => $today,
        ]);
    }

    /**
     * Export time logs as CSV (Excel-friendly). Admin only.
     */
    public function exportExcel(Request $request): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = TimeLog::query()->orderBy('log_date', 'desc')->orderBy('updated_at', 'desc');
        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('log_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('log_date', '<=', $request->date_to);
        }
        $logs = $query->get();

        $filename = 'time_logs_' . now()->format('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'User Type', 'User ID', 'User Name', 'Time In', 'Time Out', 'Source', 'IP']);
            foreach ($logs as $row) {
                fputcsv($out, [
                    $row->log_date?->format('Y-m-d'),
                    $row->user_type,
                    $row->user_id,
                    $row->user_name,
                    $row->time_in,
                    $row->time_out,
                    $row->source,
                    $row->ip_address,
                ]);
            }
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Export time logs as PDF. Admin only. (Simple HTML-based PDF via browser print or use DomPDF if available.)
     * For minimal dependency we return CSV; client can use "Print to PDF" from browser. Or we add barryvdh/laravel-dompdf.
     * Here we return a JSON with HTML content so frontend can open print dialog or use a PDF library.
     */
    public function exportPdf(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = TimeLog::query()->orderBy('log_date', 'desc')->orderBy('updated_at', 'desc');
        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('log_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('log_date', '<=', $request->date_to);
        }
        $logs = $query->get();

        $html = $this->timeLogsToHtml($logs, $request->only(['date_from', 'date_to', 'user_id', 'user_type']));
        return response()->json(['html' => $html, 'filename' => 'time_logs_' . now()->format('Y-m-d_His') . '.pdf']);
    }

    private function timeLogsToHtml($logs, array $filters): string
    {
        $rows = '';
        foreach ($logs as $row) {
            $rows .= '<tr><td>' . e($row->log_date?->format('Y-m-d')) . '</td><td>' . e($row->user_type) . '</td><td>' . e($row->user_id) . '</td><td>' . e($row->user_name) . '</td><td>' . e($row->time_in) . '</td><td>' . e($row->time_out) . '</td><td>' . e($row->source) . '</td><td>' . e($row->ip_address) . '</td></tr>';
        }
        $filterText = implode(', ', array_filter([
            $filters['date_from'] ?? null ? 'From: ' . $filters['date_from'] : null,
            $filters['date_to'] ?? null ? 'To: ' . $filters['date_to'] : null,
            isset($filters['user_id']) ? 'User ID: ' . $filters['user_id'] : null,
            $filters['user_type'] ?? null ? 'Type: ' . $filters['user_type'] : null,
        ]));
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Time Logs Report</title><style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:6px}th{background:#eee}</style></head><body><h1>Time Logs Report</h1><p>Generated: ' . now()->toDateTimeString() . '</p><p>Filters: ' . e($filterText ?: 'None') . '</p><table><thead><tr><th>Date</th><th>User Type</th><th>User ID</th><th>User Name</th><th>Time In</th><th>Time Out</th><th>Source</th><th>IP</th></tr></thead><tbody>' . $rows . '</tbody></table></body></html>';
    }
}
