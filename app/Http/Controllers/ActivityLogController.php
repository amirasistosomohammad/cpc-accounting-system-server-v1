<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /**
     * List activity logs. Admin only. Filters: user_type, user_id, action, subject_type, date_from, date_to
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ActivityLog::query()->orderBy('created_at', 'desc');

        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $items = $query->paginate($perPage);

        return response()->json($items);
    }

    /**
     * Export activity logs as CSV. Admin only.
     */
    public function exportExcel(Request $request): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ActivityLog::query()->orderBy('created_at', 'desc');
        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        $logs = $query->get();

        $filename = 'activity_logs_' . now()->format('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Created At', 'User Type', 'User ID', 'User Name', 'Action', 'Subject Type', 'Subject ID', 'Remarks', 'IP', 'User Agent']);
            foreach ($logs as $row) {
                fputcsv($out, [
                    $row->id,
                    $row->created_at?->toDateTimeString(),
                    $row->user_type,
                    $row->user_id,
                    $row->user_name,
                    $row->action,
                    $row->subject_type,
                    $row->subject_id,
                    $row->remarks,
                    $row->ip_address,
                    $row->user_agent ? substr($row->user_agent, 0, 200) : '',
                ]);
            }
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Export activity logs as PDF (HTML for print). Admin only.
     */
    public function exportPdf(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ActivityLog::query()->orderBy('created_at', 'desc');
        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        $logs = $query->get();

        $rows = '';
        foreach ($logs as $row) {
            $rows .= '<tr><td>' . e($row->id) . '</td><td>' . e($row->created_at?->toDateTimeString()) . '</td><td>' . e($row->user_type) . '</td><td>' . e($row->user_id) . '</td><td>' . e($row->user_name) . '</td><td>' . e($row->action) . '</td><td>' . e($row->subject_type) . '</td><td>' . e($row->subject_id) . '</td><td>' . e($row->remarks) . '</td><td>' . e($row->ip_address) . '</td></tr>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Activity Log Report</title><style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:6px;font-size:11px}th{background:#eee}</style></head><body><h1>Activity Log Report</h1><p>Generated: ' . now()->toDateTimeString() . '</p><table><thead><tr><th>ID</th><th>Created</th><th>User Type</th><th>User ID</th><th>User Name</th><th>Action</th><th>Subject Type</th><th>Subject ID</th><th>Remarks</th><th>IP</th></tr></thead><tbody>' . $rows . '</tbody></table></body></html>';

        return response()->json(['html' => $html, 'filename' => 'activity_logs_' . now()->format('Y-m-d_His') . '.pdf']);
    }

    /**
     * Login/Logout report index. Admin only.
     */
    public function loginLogoutReport(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ActivityLog::query()
            ->whereIn('action', ['login', 'logout'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $items = $query->paginate($perPage);

        return response()->json($items);
    }

    /**
     * Export login/logout report as CSV. Admin only.
     */
    public function exportLoginLogoutExcel(Request $request): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ActivityLog::query()
            ->whereIn('action', ['login', 'logout'])
            ->orderBy('created_at', 'desc');
        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        $logs = $query->get();

        $filename = 'login_logout_report_' . now()->format('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Created At', 'User Type', 'User ID', 'User Name', 'Action', 'IP', 'User Agent']);
            foreach ($logs as $row) {
                fputcsv($out, [
                    $row->created_at?->toDateTimeString(),
                    $row->user_type,
                    $row->user_id,
                    $row->user_name,
                    $row->action,
                    $row->ip_address,
                    $row->user_agent ? substr($row->user_agent, 0, 200) : '',
                ]);
            }
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * Export login/logout report as PDF (HTML). Admin only.
     */
    public function exportLoginLogoutPdf(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ActivityLog::query()
            ->whereIn('action', ['login', 'logout'])
            ->orderBy('created_at', 'desc');
        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        $logs = $query->get();

        $rows = '';
        foreach ($logs as $row) {
            $rows .= '<tr><td>' . e($row->created_at?->toDateTimeString()) . '</td><td>' . e($row->user_type) . '</td><td>' . e($row->user_id) . '</td><td>' . e($row->user_name) . '</td><td>' . e($row->action) . '</td><td>' . e($row->ip_address) . '</td></tr>';
        }
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Login/Logout Report</title><style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:6px}th{background:#eee}</style></head><body><h1>Login/Logout Report</h1><p>Generated: ' . now()->toDateTimeString() . '</p><table><thead><tr><th>Created</th><th>User Type</th><th>User ID</th><th>User Name</th><th>Action</th><th>IP</th></tr></thead><tbody>' . $rows . '</tbody></table></body></html>';

        return response()->json(['html' => $html, 'filename' => 'login_logout_report_' . now()->format('Y-m-d_His') . '.pdf']);
    }
}
