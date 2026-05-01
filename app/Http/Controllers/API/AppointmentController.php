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
    public function index(Request $request): JsonResponse
    {
        $query = Appointment::query()
            ->with([
                'victimReport.user:id,name,email,phone',
                'creator:id,name,email,phone',
                'assignee:id,name,email,phone',
            ])
            ->latest('scheduled_at');

        /*
        |--------------------------------------------------------------------------
        | Access rule
        |--------------------------------------------------------------------------
        | Admin/Haguruka staff: see all appointments.
        | Normal user/victim: see appointments:
        | - assigned to them
        | - created by them
        | - connected to victim reports they submitted
        |--------------------------------------------------------------------------
        */
        if (!$this->canManageAppointments($request)) {
            $userId = $request->user()?->id;

            $query->where(function ($subQuery) use ($userId) {
                $subQuery
                    ->where('assigned_to', $userId)
                    ->orWhere('created_by', $userId)
                    ->orWhereHas('victimReport', function ($caseQuery) use ($userId) {
                        $caseQuery->where('user_id', $userId);
                    });
            });
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

        if ($request->filled('assigned_to') && $request->assigned_to !== 'all') {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->filled('victim_report_id')) {
            $query->where('victim_report_id', $request->victim_report_id);
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($subQuery) use ($q) {
                $subQuery
                    ->where('client_name', 'like', "%{$q}%")
                    ->orWhere('district', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%")
                    ->orWhere('appointment_type', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%")
                    ->orWhereHas('assignee', function ($userQuery) use ($q) {
                        $userQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    })
                    ->orWhereHas('creator', function ($userQuery) use ($q) {
                        $userQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    })
                    ->orWhereHas('victimReport', function ($reportQuery) use ($q) {
                        $reportQuery
                            ->where('case_type', 'like', "%{$q}%")
                            ->orWhere('details', 'like', "%{$q}%")
                            ->orWhere('status', 'like', "%{$q}%")
                            ->orWhere('urgency', 'like', "%{$q}%");
                    })
                    ->orWhereHas('victimReport.user', function ($userQuery) use ($q) {
                        $userQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $appointments = $query->paginate($perPage);

        $appointments->getCollection()->transform(function ($appointment) {
            return $this->transformAppointment($appointment);
        });

        return response()->json([
            'success' => true,
            'message' => 'Appointments fetched successfully.',
            'data'    => $appointments,
        ]);
    }

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
            'data'    => $this->transformAppointment($appointment),
        ]);
    }

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
            'data'    => $this->transformAppointment($appointment),
        ], 201);
    }

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
            'data'    => $this->transformAppointment($appointment),
        ]);
    }

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

    private function syncCaseStatusFromAppointments(?int $victimReportId): void
    {
        if (!$victimReportId) {
            return;
        }

        $report = VictimReport::find($victimReportId);

        if (!$report) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Do not reopen or overwrite final victim decisions/admin final statuses.
        |--------------------------------------------------------------------------
        */
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

    private function canAccessAppointment(Request $request, Appointment $appointment): bool
    {
        if ($this->canManageAppointments($request)) {
            return true;
        }

        $appointment->loadMissing('victimReport');

        $userId = (int) $request->user()?->id;

        return (int) $appointment->created_by === $userId
            || (int) $appointment->assigned_to === $userId
            || (int) $appointment->victimReport?->user_id === $userId;
    }

    private function canManageAppointments(Request $request): bool
    {
        $user = $request->user();

        if (!$user || !method_exists($user, 'roles')) {
            return false;
        }

        $slugs = $user->roles()->pluck('slug')->toArray();

        return in_array('admin', $slugs, true)
            || in_array('haguruka_staff', $slugs, true);
    }

    private function transformAppointment(Appointment $appointment): array
    {
        $report = $appointment->victimReport;
        $reportUser = $report?->user;

        $clientName = $appointment->client_name
            ?: $reportUser?->name
            ?: 'Anonymous';

        return [
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

            'client_name'      => $clientName,
            'client_email'     => $reportUser?->email,
            'client_phone'     => $reportUser?->phone,

            'appointment_type' => $appointment->appointment_type,
            'district'         => $appointment->district,
            'scheduled_at'     => optional($appointment->scheduled_at)->toDateTimeString(),
            'scheduled_date'   => optional($appointment->scheduled_at)->format('Y-m-d'),
            'scheduled_time'   => optional($appointment->scheduled_at)->format('H:i'),

            'status'           => $appointment->status,
            'notes'            => $appointment->notes,

            'created_by'       => $appointment->created_by,
            'creator'          => $appointment->creator ? [
                'id'    => $appointment->creator->id,
                'name'  => $appointment->creator->name,
                'email' => $appointment->creator->email,
                'phone' => $appointment->creator->phone,
            ] : null,

            'assigned_to'      => $appointment->assigned_to,
            'assignee'         => $appointment->assignee ? [
                'id'    => $appointment->assignee->id,
                'name'  => $appointment->assignee->name,
                'email' => $appointment->assignee->email,
                'phone' => $appointment->assignee->phone,
            ] : null,

            'completed_at'     => optional($appointment->completed_at)->toDateTimeString(),
            'cancelled_at'     => optional($appointment->cancelled_at)->toDateTimeString(),
            'created_at'       => optional($appointment->created_at)->toDateTimeString(),
            'updated_at'       => optional($appointment->updated_at)->toDateTimeString(),
        ];
    }
}