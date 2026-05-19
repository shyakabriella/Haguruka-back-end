<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\VictimReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AppointmentController extends Controller
{
    /**
     * List appointments.
     *
     * SECURITY RULE:
     * - Admin / Haguruka staff can see all appointments.
     * - Victim can see ONLY appointments connected to his/her own reports.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Appointment::query()
            ->with([
                'victimReport.user:id,name,email,phone',
                'creator:id,name,email,phone',
                'assignee:id,name,email,phone',
            ])
            ->latest('scheduled_at');

        if (!$this->canManageAppointments($request)) {
            $userId = $request->user()?->id;

            if (!$userId) {
                return response()->json([
                    'success' => true,
                    'message' => 'Appointments fetched successfully.',
                    'data'    => [
                        'data' => [],
                        'current_page' => 1,
                        'per_page' => 10,
                        'total' => 0,
                    ],
                ]);
            }

            $query->whereHas('victimReport', function ($caseQuery) use ($userId) {
                $caseQuery->where('user_id', $userId);
            });
        }

        if ($request->filled('victim_report_id')) {
            $report = VictimReport::find($request->victim_report_id);

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Victim report not found.',
                ], 404);
            }

            if (!$this->canAccessCase($request, $report)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to access appointments for this case.',
                ], 403);
            }

            $query->where('victim_report_id', $report->id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('appointment_type', $request->type);
        }

        if ($request->filled('appointment_type') && $request->appointment_type !== 'all') {
            $query->where('appointment_type', $request->appointment_type);
        }

        if ($this->canManageAppointments($request)) {
            if ($request->filled('assigned_to') && $request->assigned_to !== 'all') {
                $query->where('assigned_to', $request->assigned_to);
            }
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->q);

            $query->where(function ($subQuery) use ($q) {
                $subQuery
                    ->where('client_name', 'like', "%{$q}%")
                    ->orWhere('district', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%")
                    ->orWhere('appointment_type', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%")
                    ->orWhereHas('victimReport', function ($reportQuery) use ($q) {
                        $reportQuery
                            ->where('case_type', 'like', "%{$q}%")
                            ->orWhere('details', 'like', "%{$q}%")
                            ->orWhere('status', 'like', "%{$q}%")
                            ->orWhere('urgency', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $appointments = $query->paginate($perPage);

        $appointments->getCollection()->transform(function ($appointment) use ($request) {
            return $this->transformAppointment($request, $appointment);
        });

        return response()->json([
            'success' => true,
            'message' => 'Appointments fetched successfully.',
            'data'    => $appointments,
        ]);
    }

    /**
     * Show one appointment.
     *
     * SECURITY RULE:
     * Victim cannot open another victim appointment by changing appointment ID.
     */
    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $appointment->load([
            'victimReport.user:id,name,email,phone',
            'creator:id,name,email,phone',
            'assignee:id,name,email,phone',
        ]);

        if (!$this->canAccessAppointment($request, $appointment)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to access this appointment.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment fetched successfully.',
            'data'    => $this->transformAppointment($request, $appointment),
        ]);
    }

    /**
     * Create appointment.
     *
     * SECURITY RULE:
     * Only admin / Haguruka staff can create appointments.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$this->canManageAppointments($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can create appointments.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'victim_report_id' => ['nullable', 'exists:victim_reports,id'],
            'assigned_to'      => ['nullable', 'exists:users,id'],
            'client_name'      => ['nullable', 'string', 'max:255'],
            'appointment_type' => ['required', 'in:phone_call,in_person,isange_referral,police_referral'],
            'district'         => ['nullable', 'string', 'max:255'],
            'scheduled_at'     => ['required', 'date'],
            'status'           => ['nullable', 'in:scheduled,completed,cancelled'],
            'notes'            => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $status = $validated['status'] ?? 'scheduled';

        $appointment = Appointment::create([
            'victim_report_id' => $validated['victim_report_id'] ?? null,
            'created_by'       => $request->user()?->id,
            'assigned_to'      => $validated['assigned_to'] ?? null,
            'client_name'      => $validated['client_name'] ?? null,
            'appointment_type' => $validated['appointment_type'],
            'district'         => $validated['district'] ?? null,
            'scheduled_at'     => $validated['scheduled_at'],
            'status'           => $status,
            'notes'            => $validated['notes'] ?? null,
            'completed_at'     => $status === 'completed' ? now() : null,
            'cancelled_at'     => $status === 'cancelled' ? now() : null,
        ]);

        if (!empty($validated['victim_report_id'])) {
            $this->syncCaseStatusFromAppointments((int) $validated['victim_report_id']);
        }

        $appointment->load([
            'victimReport.user:id,name,email,phone',
            'creator:id,name,email,phone',
            'assignee:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Appointment created successfully.',
            'data'    => $this->transformAppointment($request, $appointment),
        ], 201);
    }

    /**
     * Update appointment.
     *
     * SECURITY RULE:
     * Only admin / Haguruka staff can update appointments.
     */
    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        if (!$this->canManageAppointments($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can update appointments.',
            ], 403);
        }

        $oldVictimReportId = $appointment->victim_report_id;

        $validator = Validator::make($request->all(), [
            'victim_report_id' => ['sometimes', 'nullable', 'exists:victim_reports,id'],
            'assigned_to'      => ['sometimes', 'nullable', 'exists:users,id'],
            'client_name'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'appointment_type' => ['sometimes', 'required', 'in:phone_call,in_person,isange_referral,police_referral'],
            'district'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'scheduled_at'     => ['sometimes', 'required', 'date'],
            'status'           => ['sometimes', 'required', 'in:scheduled,completed,cancelled'],
            'notes'            => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if (isset($validated['status'])) {
            if ($validated['status'] === 'completed') {
                $validated['completed_at'] = now();
                $validated['cancelled_at'] = null;
            }

            if ($validated['status'] === 'cancelled') {
                $validated['cancelled_at'] = now();
                $validated['completed_at'] = null;
            }

            if ($validated['status'] === 'scheduled') {
                $validated['completed_at'] = null;
                $validated['cancelled_at'] = null;
            }
        }

        $appointment->update($validated);

        $newVictimReportId = $appointment->fresh()?->victim_report_id;

        if ($oldVictimReportId) {
            $this->syncCaseStatusFromAppointments((int) $oldVictimReportId);
        }

        if ($newVictimReportId && (int) $newVictimReportId !== (int) $oldVictimReportId) {
            $this->syncCaseStatusFromAppointments((int) $newVictimReportId);
        }

        if ($newVictimReportId && !$oldVictimReportId) {
            $this->syncCaseStatusFromAppointments((int) $newVictimReportId);
        }

        $appointment->load([
            'victimReport.user:id,name,email,phone',
            'creator:id,name,email,phone',
            'assignee:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Appointment updated successfully.',
            'data'    => $this->transformAppointment($request, $appointment),
        ]);
    }

    /**
     * Delete appointment.
     *
     * SECURITY RULE:
     * Only admin / Haguruka staff can delete appointments.
     */
    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        if (!$this->canManageAppointments($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can delete appointments.',
            ], 403);
        }

        $victimReportId = $appointment->victim_report_id;

        $appointment->delete();

        if ($victimReportId) {
            $this->syncCaseStatusFromAppointments((int) $victimReportId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment deleted successfully.',
        ]);
    }

    /**
     * Sync case status from appointments.
     */
    private function syncCaseStatusFromAppointments(?int $victimReportId): void
    {
        if (!$victimReportId) {
            return;
        }

        $report = VictimReport::find($victimReportId);

        if (!$report) {
            return;
        }

        if (in_array($report->status, ['closed', 'withdrawn', 'rejected'], true)) {
            return;
        }

        $appointments = Appointment::where('victim_report_id', $victimReportId)->get();

        if ($appointments->isEmpty()) {
            return;
        }

        $hasScheduled = $appointments->contains(function ($appointment) {
            return $appointment->status === 'scheduled';
        });

        $allFinished = $appointments->every(function ($appointment) {
            return in_array($appointment->status, ['completed', 'cancelled'], true);
        });

        $hasCompleted = $appointments->contains(function ($appointment) {
            return $appointment->status === 'completed';
        });

        if ($allFinished && $hasCompleted) {
            $report->status = 'resolved';
            $report->save();
            return;
        }

        if ($hasScheduled && in_array($report->status, ['submitted', 'pending', 'under_review', 'in_progress', null], true)) {
            $report->status = 'in_progress';
            $report->save();
        }
    }

    /**
     * Victim can access only appointment connected to his/her own case.
     */
    private function canAccessAppointment(Request $request, Appointment $appointment): bool
    {
        if ($this->canManageAppointments($request)) {
            return true;
        }

        $appointment->loadMissing('victimReport');

        $userId = $request->user()?->id;

        if (!$userId) {
            return false;
        }

        return (int) $appointment->victimReport?->user_id === (int) $userId;
    }

    /**
     * Victim can access only his/her own report.
     */
    private function canAccessCase(Request $request, VictimReport $report): bool
    {
        if ($this->canManageAppointments($request)) {
            return true;
        }

        $userId = $request->user()?->id;

        if (!$userId) {
            return false;
        }

        return (int) $report->user_id === (int) $userId;
    }

    /**
     * Admin/staff permission.
     *
     * Supports:
     * - users.role
     * - users.role_slug
     * - users.user_role
     * - users.type
     * - roles relationship with slug/name
     */
    private function canManageAppointments(Request $request): bool
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

        $roles = $this->getUserRoleSlugs($user);

        foreach ($roles as $role) {
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
     * Transform appointment response.
     *
     * SECURITY:
     * Victim receives only minimal staff/admin data.
     */
    private function transformAppointment(Request $request, Appointment $appointment): array
    {
        $report = $appointment->victimReport;
        $reportUser = $report?->user;
        $isManager = $this->canManageAppointments($request);

        $clientName = $appointment->client_name
            ?: $reportUser?->name
            ?: 'Anonymous';

        $base = [
            'id'               => $appointment->id,
            'appointment_code' => 'APT-' . str_pad($appointment->id, 4, '0', STR_PAD_LEFT),

            'victim_report_id' => $appointment->victim_report_id,
            'case_code'        => $appointment->victim_report_id
                ? 'CASE-' . str_pad($appointment->victim_report_id, 4, '0', STR_PAD_LEFT)
                : 'No Case',

            'victim_report'    => $report ? [
                'id'        => $report->id,
                'case_code' => 'CASE-' . str_pad($report->id, 4, '0', STR_PAD_LEFT),
                'case_type' => $report->case_type,
                'status'    => $report->status,
                'urgency'   => $report->urgency,
                'user_id'   => $report->user_id,
            ] : null,

            'appointment_type' => $appointment->appointment_type,
            'district'         => $appointment->district,
            'scheduled_at'     => optional($appointment->scheduled_at)->toDateTimeString(),
            'scheduled_date'   => optional($appointment->scheduled_at)->format('Y-m-d'),
            'scheduled_time'   => optional($appointment->scheduled_at)->format('H:i'),

            'status'           => $appointment->status,
            'notes'            => $appointment->notes,

            'assigned_to'      => $appointment->assigned_to,
            'assignee'         => $appointment->assignee ? [
                'id'    => $appointment->assignee->id,
                'name'  => $appointment->assignee->name,
                'email' => $isManager ? $appointment->assignee->email : null,
                'phone' => $isManager ? $appointment->assignee->phone : null,
            ] : null,

            'completed_at'     => optional($appointment->completed_at)->toDateTimeString(),
            'cancelled_at'     => optional($appointment->cancelled_at)->toDateTimeString(),
            'created_at'       => optional($appointment->created_at)->toDateTimeString(),
            'updated_at'       => optional($appointment->updated_at)->toDateTimeString(),
        ];

        if ($isManager) {
            $base['client_name'] = $clientName;
            $base['client_email'] = $reportUser?->email;
            $base['client_phone'] = $reportUser?->phone;

            $base['created_by'] = $appointment->created_by;
            $base['creator'] = $appointment->creator ? [
                'id'    => $appointment->creator->id,
                'name'  => $appointment->creator->name,
                'email' => $appointment->creator->email,
                'phone' => $appointment->creator->phone,
            ] : null;
        } else {
            $base['client_name'] = 'You';
            $base['client_email'] = null;
            $base['client_phone'] = null;

            $base['created_by'] = null;
            $base['creator'] = $appointment->creator ? [
                'id'    => $appointment->creator->id,
                'name'  => $appointment->creator->name,
                'email' => null,
                'phone' => null,
            ] : null;
        }

        return $base;
    }
}