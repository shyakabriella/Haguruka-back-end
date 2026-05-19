<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReportEvidence;
use App\Models\VictimReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VictimReportController extends Controller
{
    /**
     * List victim reports.
     *
     * Admin/staff can see all.
     * Victim can see only own cases.
     */
    public function index(Request $request): JsonResponse
    {
        $query = VictimReport::query()
            ->with([
                'user:id,name,email,phone',
                'evidences',
            ])
            ->latest();

        if (!$this->canManageCases($request)) {
            $userId = $request->user()?->id;

            if (!$userId) {
                return response()->json([
                    'success' => true,
                    'message' => 'Victim reports fetched successfully.',
                    'data' => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 10,
                        'total' => 0,
                    ],
                ]);
            }

            $query->where('user_id', $userId);
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
            $q = trim((string) $request->q);

            $query->where(function ($subQuery) use ($q, $request) {
                $subQuery
                    ->where('details', 'like', "%{$q}%")
                    ->orWhere('case_type', 'like', "%{$q}%")
                    ->orWhere('urgency', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%");

                if ($this->canManageCases($request)) {
                    $subQuery->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    });
                }
            });
        }

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $reports = $query->paginate($perPage);

        $reports->getCollection()->transform(function ($report) use ($request) {
            return $this->transformReport($request, $report);
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
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

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

            'evidence'      => ['nullable'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $report = VictimReport::create([
            'user_id'       => $request->user()->id,
            'language'      => $validated['language'] ?? 'en',
            'reporter_role' => $validated['reporter_role'] ?? 'victim',
            'urgency'       => $this->normalizeUrgency($validated['urgency'] ?? 'low'),
            'case_type'     => $validated['case_type'] ?? 'other',
            'input_mode'    => $validated['input_mode'] ?? 'text',
            'details'       => $validated['details'] ?? null,
            'latitude'      => $validated['latitude'] ?? null,
            'longitude'     => $validated['longitude'] ?? null,
            'status'        => 'submitted',
        ]);

        $this->storeEvidenceFiles($request, $report);

        $report->load([
            'user:id,name,email,phone',
            'evidences',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Victim report submitted successfully.',
            'data'    => $this->transformReport($request, $report),
        ], 201);
    }

    /**
     * Quick emergency report.
     */
    public function quickEmergency(Request $request): JsonResponse
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

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

        $report = VictimReport::create([
            'user_id'       => $request->user()->id,
            'language'      => $validated['language'] ?? 'en',
            'reporter_role' => 'victim',
            'urgency'       => 'urgent',
            'case_type'     => 'emergency',
            'input_mode'    => 'quick_emergency',
            'details'       => $validated['details']
                ?? 'Quick emergency alert submitted from mobile dashboard. Victim may be in immediate danger and needs urgent support.',
            'latitude'      => $validated['latitude'] ?? null,
            'longitude'     => $validated['longitude'] ?? null,
            'status'        => 'submitted',
        ]);

        $report->load([
            'user:id,name,email,phone',
            'evidences',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Emergency report submitted successfully.',
            'data'    => $this->transformReport($request, $report),
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
            'data'    => $this->transformReport($request, $report),
        ]);
    }

    /**
     * Admin/staff update case status.
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

        $newStatus = strtolower(trim((string) $request->status));
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

            if (Schema::hasColumn('victim_reports', 'closed_reason')) {
                $report->closed_reason = null;
            }

            if (Schema::hasColumn('victim_reports', 'closed_at')) {
                $report->closed_at = null;
            }

            if (Schema::hasColumn('victim_reports', 'closed_by')) {
                $report->closed_by = null;
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

            if (Schema::hasColumn('victim_reports', 'withdraw_reason')) {
                $report->withdraw_reason = null;
            }

            if (Schema::hasColumn('victim_reports', 'withdrawn_at')) {
                $report->withdrawn_at = null;
            }

            if (Schema::hasColumn('victim_reports', 'withdrawn_by')) {
                $report->withdrawn_by = null;
            }
        }

        $report->save();

        $report->load([
            'user:id,name,email,phone',
            'evidences',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Case status updated successfully.',
            'data'    => $this->transformReport($request, $report),
        ]);
    }

    /**
     * Save evidence files.
     */
    private function storeEvidenceFiles(Request $request, VictimReport $report): void
    {
        $files = [];

        if ($request->hasFile('evidence')) {
            $singleFile = $request->file('evidence');

            if (is_array($singleFile)) {
                foreach ($singleFile as $file) {
                    if ($file && $file->isValid()) {
                        $files[] = $file;
                    }
                }
            } elseif ($singleFile && $singleFile->isValid()) {
                $files[] = $singleFile;
            }
        }

        if ($request->hasFile('evidences')) {
            $uploadedFiles = $request->file('evidences');

            if (is_array($uploadedFiles)) {
                foreach ($uploadedFiles as $file) {
                    if ($file && $file->isValid()) {
                        $files[] = $file;
                    }
                }
            } elseif ($uploadedFiles && $uploadedFiles->isValid()) {
                $files[] = $uploadedFiles;
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
     * Victim can access only own report.
     */
    private function canAccessCase(Request $request, VictimReport $report): bool
    {
        if ($this->canManageCases($request)) {
            return true;
        }

        $userId = $request->user()?->id;

        if (!$userId) {
            return false;
        }

        return (int) $report->user_id === (int) $userId;
    }

    /**
     * Admin/staff permission checker.
     */
    private function canManageCases(Request $request): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        $allowedRoles = [
            'admin',
            'super_admin',
            'haguruka_staff',
            'staff',
            'case_manager',
        ];

        foreach ($this->getUserRoleSlugs($user) as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user roles safely.
     */
    private function getUserRoleSlugs($user): array
    {
        $roles = [];

        foreach (['role', 'role_slug', 'user_role', 'type'] as $field) {
            if (!empty($user->{$field}) && is_string($user->{$field})) {
                $roles[] = strtolower(trim($user->{$field}));
            }
        }

        if (!empty($user->role) && is_object($user->role)) {
            foreach (['slug', 'name'] as $field) {
                if (!empty($user->role->{$field})) {
                    $roles[] = strtolower(trim((string) $user->role->{$field}));
                }
            }
        }

        try {
            if (method_exists($user, 'roles')) {
                foreach ($user->roles()->get() as $role) {
                    foreach (['slug', 'name'] as $field) {
                        if (!empty($role->{$field})) {
                            $roles[] = strtolower(trim((string) $role->{$field}));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            //
        }

        return array_values(array_unique(array_filter($roles)));
    }

    /**
     * Transform report response.
     */
    private function transformReport(Request $request, VictimReport $report): array
    {
        $reporter = $report->user;
        $isManager = $this->canManageCases($request);

        $data = [
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

            'withdraw_reason' => $report->withdraw_reason ?? null,
            'withdrawn_at'    => optional($report->withdrawn_at)->toDateTimeString(),
            'withdrawn_by'    => $report->withdrawn_by ?? null,

            'closed_reason'   => $report->closed_reason ?? null,
            'closed_at'       => optional($report->closed_at)->toDateTimeString(),
            'closed_by'       => $report->closed_by ?? null,

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

        if ($isManager) {
            $data['reporter_name'] = $reporter?->name;
            $data['reporter_email'] = $reporter?->email;
            $data['reporter_phone'] = $reporter?->phone;

            $data['reporter_user'] = $reporter ? [
                'id'    => $reporter->id,
                'name'  => $reporter->name,
                'email' => $reporter->email,
                'phone' => $reporter->phone,
            ] : null;
        } else {
            $data['reporter_name'] = 'You';
            $data['reporter_email'] = null;
            $data['reporter_phone'] = null;

            $data['reporter_user'] = $reporter ? [
                'id'    => $reporter->id,
                'name'  => $reporter->name,
                'email' => null,
                'phone' => null,
            ] : null;
        }

        return $data;
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
     * Normalize urgency.
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
}