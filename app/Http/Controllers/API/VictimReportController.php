<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReportEvidence;
use App\Models\VictimReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VictimReportController extends Controller
{
    /**
     * Get victim reports.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VictimReport::query()
            ->with([
                'user:id,name,email,phone',
                'evidences',
                'withdrawnBy:id,name,email,phone',
                'closedBy:id,name,email,phone',
            ])
            ->latest();

        if (!$this->canManageCases($request)) {
            $query->where('user_id', $request->user()?->id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('case_type') && $request->case_type !== 'all') {
            $query->where('case_type', $request->case_type);
        }

        if ($request->filled('urgency') && $request->urgency !== 'all') {
            $query->where('urgency', $this->normalizeUrgency($request->urgency));
        }

        if ($request->filled('input_mode') && $request->input_mode !== 'all') {
            $query->where('input_mode', $request->input_mode);
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($subQuery) use ($q) {
                $subQuery
                    ->where('details', 'like', "%{$q}%")
                    ->orWhere('case_type', 'like', "%{$q}%")
                    ->orWhere('urgency', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%")
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $reports = $query->paginate($perPage);

        $reports->getCollection()->transform(function ($report) {
            return $this->transformReport($report);
        });

        return response()->json([
            'success' => true,
            'message' => 'Victim reports fetched successfully.',
            'data'    => $reports,
        ]);
    }

    /**
     * Store normal victim report.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'language'      => ['nullable', 'string', 'max:20'],
            'reporter_role' => ['nullable', 'string', 'max:50'],
            'urgency'       => ['nullable', 'string', 'max:50'],
            'case_type'     => ['nullable', 'string', 'max:100'],
            'input_mode'    => ['nullable', 'string', 'max:100'],
            'details'       => ['nullable', 'string'],
            'latitude'      => ['nullable', 'numeric'],
            'longitude'     => ['nullable', 'numeric'],

            'evidences'     => ['nullable', 'array'],
            'evidences.*'   => ['file', 'max:20480'],
            'evidence'      => ['nullable', 'file', 'max:20480'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $urgency = $this->safeColumnValue(
            'victim_reports',
            'urgency',
            $this->normalizeUrgency($validated['urgency'] ?? 'low'),
            'low'
        );

        $caseType = $this->safeColumnValue(
            'victim_reports',
            'case_type',
            $validated['case_type'] ?? 'other',
            'other'
        );

        $inputMode = $this->safeColumnValue(
            'victim_reports',
            'input_mode',
            $validated['input_mode'] ?? 'text',
            'text'
        );

        $status = $this->safeColumnValue(
            'victim_reports',
            'status',
            'submitted',
            'submitted'
        );

        $report = VictimReport::create([
            'user_id'       => $request->user()?->id,
            'language'      => $validated['language'] ?? 'en',
            'reporter_role' => $validated['reporter_role'] ?? 'victim',
            'urgency'       => $urgency,
            'case_type'     => $caseType,
            'input_mode'    => $inputMode,
            'details'       => $validated['details'] ?? null,
            'latitude'      => $validated['latitude'] ?? null,
            'longitude'     => $validated['longitude'] ?? null,
            'status'        => $status,
        ]);

        $this->storeEvidenceFiles($request, $report);

        $report->load([
            'user:id,name,email,phone',
            'evidences',
            'withdrawnBy:id,name,email,phone',
            'closedBy:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Victim report submitted successfully.',
            'data'    => $this->transformReport($report),
        ], 201);
    }

    /**
     * Quick emergency report from mobile app.
     */
    public function quickEmergency(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'language'  => ['nullable', 'string', 'max:20'],
            'details'   => ['nullable', 'string'],
            'latitude'  => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        /*
        |--------------------------------------------------------------------------
        | Important fix
        |--------------------------------------------------------------------------
        | Your database rejected urgency = "high".
        | So Quick Emergency now stores urgency = "urgent".
        |--------------------------------------------------------------------------
        */
        $urgency = $this->safeColumnValue(
            'victim_reports',
            'urgency',
            'urgent',
            'low'
        );

        /*
        |--------------------------------------------------------------------------
        | Safe values for enum databases
        |--------------------------------------------------------------------------
        | If your case_type column does not allow "emergency", it will fallback
        | to "other" automatically.
        |--------------------------------------------------------------------------
        */
        $caseType = $this->safeColumnValue(
            'victim_reports',
            'case_type',
            'emergency',
            'other'
        );

        $inputMode = $this->safeColumnValue(
            'victim_reports',
            'input_mode',
            'quick_emergency',
            'text'
        );

        $status = $this->safeColumnValue(
            'victim_reports',
            'status',
            'submitted',
            'submitted'
        );

        $report = VictimReport::create([
            'user_id'       => $request->user()?->id,
            'language'      => $validated['language'] ?? 'en',
            'reporter_role' => 'victim',
            'urgency'       => $urgency,
            'case_type'     => $caseType,
            'input_mode'    => $inputMode,
            'details'       => $validated['details']
                ?? 'Quick emergency alert submitted from mobile dashboard. Victim may be in immediate danger and needs urgent support.',
            'latitude'      => $validated['latitude'] ?? null,
            'longitude'     => $validated['longitude'] ?? null,
            'status'        => $status,
        ]);

        $report->load([
            'user:id,name,email,phone',
            'evidences',
            'withdrawnBy:id,name,email,phone',
            'closedBy:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Emergency report submitted successfully.',
            'data'    => $this->transformReport($report),
        ], 201);
    }

    /**
     * Show one victim report.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $report = VictimReport::with([
            'user:id,name,email,phone',
            'evidences',
            'withdrawnBy:id,name,email,phone',
            'closedBy:id,name,email,phone',
        ])->find($id);

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Victim report not found.',
            ], 404);
        }

        if (!$this->canAccessCase($request, $report)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to view this report.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Victim report fetched successfully.',
            'data'    => $this->transformReport($report),
        ]);
    }

    /**
     * Update case status from admin dashboard.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageCases($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can update case status.',
            ], 403);
        }

        $report = VictimReport::find($id);

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Victim report not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'motif'  => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $newStatus = $this->safeColumnValue(
            'victim_reports',
            'status',
            $request->status,
            $report->status ?: 'submitted'
        );

        $reason = $request->input('reason') ?: $request->input('motif');

        $report->status = $newStatus;

        if ($newStatus === 'withdrawn') {
            if (Schema::hasColumn('victim_reports', 'withdraw_reason')) {
                $report->withdraw_reason = $reason;
            }

            if (Schema::hasColumn('victim_reports', 'withdrawn_at')) {
                $report->withdrawn_at = now();
            }

            if (Schema::hasColumn('victim_reports', 'withdrawn_by')) {
                $report->withdrawn_by = $request->user()?->id;
            }
        }

        if ($newStatus === 'closed') {
            if (Schema::hasColumn('victim_reports', 'closed_reason')) {
                $report->closed_reason = $reason;
            }

            if (Schema::hasColumn('victim_reports', 'closed_at')) {
                $report->closed_at = now();
            }

            if (Schema::hasColumn('victim_reports', 'closed_by')) {
                $report->closed_by = $request->user()?->id;
            }
        }

        $report->save();

        $report->load([
            'user:id,name,email,phone',
            'evidences',
            'withdrawnBy:id,name,email,phone',
            'closedBy:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Case status updated successfully.',
            'data'    => $this->transformReport($report),
        ]);
    }

    /**
     * Save evidence files.
     */
    private function storeEvidenceFiles(Request $request, VictimReport $report): void
    {
        $files = [];

        if ($request->hasFile('evidence')) {
            $files[] = $request->file('evidence');
        }

        if ($request->hasFile('evidences')) {
            foreach ($request->file('evidences') as $file) {
                $files[] = $file;
            }
        }

        foreach ($files as $file) {
            $path = $file->store('report-evidences', 'public');

            ReportEvidence::create([
                'victim_report_id' => $report->id,
                'file_name'        => $file->getClientOriginalName(),
                'file_type'        => $file->getClientMimeType(),
                'file_size'        => $file->getSize(),
                'file_path'        => $path,
                'file_url'         => Storage::disk('public')->url($path),
            ]);
        }
    }

    /**
     * Transform report response.
     */
    private function transformReport(VictimReport $report): array
    {
        $reporter = $report->user;

        return [
            'id'             => $report->id,
            'case_code'      => 'CASE-' . str_pad($report->id, 4, '0', STR_PAD_LEFT),

            'user_id'        => $report->user_id,
            'language'       => $report->language,
            'reporter_role'  => $report->reporter_role,
            'urgency'        => $report->urgency,
            'case_type'      => $report->case_type,
            'input_mode'     => $report->input_mode,
            'details'        => $report->details,
            'latitude'       => $report->latitude,
            'longitude'      => $report->longitude,
            'status'         => $report->status,

            'reporter_name'  => $reporter?->name,
            'reporter_email' => $reporter?->email,
            'reporter_phone' => $reporter?->phone,

            'reporter_user'  => $reporter ? [
                'id'    => $reporter->id,
                'name'  => $reporter->name,
                'email' => $reporter->email,
                'phone' => $reporter->phone,
            ] : null,

            'withdraw_reason' => $report->withdraw_reason ?? null,
            'withdrawn_at'    => optional($report->withdrawn_at)->toDateTimeString(),
            'withdrawn_by'    => $report->withdrawn_by ?? null,
            'withdrawn_by_user' => $report->withdrawnBy ? [
                'id'    => $report->withdrawnBy->id,
                'name'  => $report->withdrawnBy->name,
                'email' => $report->withdrawnBy->email,
                'phone' => $report->withdrawnBy->phone,
            ] : null,

            'closed_reason' => $report->closed_reason ?? null,
            'closed_at'     => optional($report->closed_at)->toDateTimeString(),
            'closed_by'     => $report->closed_by ?? null,
            'closed_by_user' => $report->closedBy ? [
                'id'    => $report->closedBy->id,
                'name'  => $report->closedBy->name,
                'email' => $report->closedBy->email,
                'phone' => $report->closedBy->phone,
            ] : null,

            'evidences' => $report->relationLoaded('evidences')
                ? $report->evidences->map(function ($evidence) {
                    return [
                        'id'        => $evidence->id,
                        'file_name' => $evidence->file_name ?? null,
                        'file_type' => $evidence->file_type ?? null,
                        'file_size' => $evidence->file_size ?? null,
                        'file_path' => $evidence->file_path ?? null,
                        'file_url'  => $this->resolveEvidenceUrl($evidence),
                    ];
                })->values()
                : [],

            'created_at' => optional($report->created_at)->toDateTimeString(),
            'updated_at' => optional($report->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * Resolve evidence URL.
     */
    private function resolveEvidenceUrl($evidence): ?string
    {
        if (!empty($evidence->file_url)) {
            return $evidence->file_url;
        }

        if (!empty($evidence->file_path)) {
            return Storage::disk('public')->url($evidence->file_path);
        }

        return null;
    }

    /**
     * Victim can access only their cases.
     * Admin/staff can access all.
     */
    private function canAccessCase(Request $request, VictimReport $report): bool
    {
        if ($this->canManageCases($request)) {
            return true;
        }

        return (int) $report->user_id === (int) $request->user()?->id;
    }

    /**
     * Admin/staff permission.
     */
    private function canManageCases(Request $request): bool
    {
        $user = $request->user();

        if (!$user || !method_exists($user, 'roles')) {
            return false;
        }

        $slugs = $user->roles()->pluck('slug')->toArray();

        return in_array('admin', $slugs, true)
            || in_array('haguruka_staff', $slugs, true);
    }

    /**
     * Convert unsupported urgency values before saving.
     */
    private function normalizeUrgency(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'high', 'emergency', 'critical' => 'urgent',
            'support' => 'medium',
            'medium' => 'medium',
            'low' => 'low',
            'urgent' => 'urgent',
            default => 'low',
        };
    }

    /**
     * Safely save values for ENUM columns.
     *
     * If the column is enum and the wanted value is not allowed,
     * fallback will be used.
     */
    private function safeColumnValue(
        string $table,
        string $column,
        ?string $wantedValue,
        string $fallback
    ): string {
        $wantedValue = strtolower(trim((string) $wantedValue));
        $fallback = strtolower(trim($fallback));

        $allowed = $this->getEnumAllowedValues($table, $column);

        if (empty($allowed)) {
            return $wantedValue ?: $fallback;
        }

        if (in_array($wantedValue, $allowed, true)) {
            return $wantedValue;
        }

        if (in_array($fallback, $allowed, true)) {
            return $fallback;
        }

        return $allowed[0];
    }

    /**
     * Read enum allowed values from MySQL column.
     */
    private function getEnumAllowedValues(string $table, string $column): array
    {
        try {
            if (!Schema::hasColumn($table, $column)) {
                return [];
            }

            $result = DB::select("SHOW COLUMNS FROM {$table} LIKE ?", [$column]);

            if (empty($result)) {
                return [];
            }

            $type = $result[0]->Type ?? '';

            if (!str_starts_with($type, 'enum(')) {
                return [];
            }

            preg_match_all("/'([^']+)'/", $type, $matches);

            return array_map(function ($value) {
                return strtolower(trim($value));
            }, $matches[1] ?? []);
        } catch (\Throwable $e) {
            return [];
        }
    }
}