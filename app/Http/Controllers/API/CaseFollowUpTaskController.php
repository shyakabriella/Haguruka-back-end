<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CaseFollowUpTask;
use App\Models\VictimReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CaseFollowUpTaskController extends Controller
{
    /**
     * List follow-up tasks for one case.
     *
     * SECURITY:
     * - Admin/staff can see tasks for any case.
     * - Victim can see tasks only for his/her own case.
     */
    public function index(Request $request, VictimReport $report): JsonResponse
    {
        if (!$this->canAccessCase($request, $report)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to access this case.',
            ], 403);
        }

        $query = $report->followUpTasks()
            ->with([
                'creator:id,name,email,phone',
                'assignee:id,name,email,phone',
            ])
            ->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }

        /*
        |--------------------------------------------------------------------------
        | Important privacy rule
        |--------------------------------------------------------------------------
        | assigned_to filter is useful for admin/staff dashboard.
        | Victim should not filter by staff/user IDs.
        |--------------------------------------------------------------------------
        */
        if ($this->canManageCases($request)) {
            if ($request->filled('assigned_to') && $request->assigned_to !== 'all') {
                $query->where('assigned_to', $request->assigned_to);
            }
        }

        $tasks = $query->get()->map(function ($task) use ($request) {
            return $this->transformTask($request, $task);
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Follow-up tasks fetched successfully.',
            'data'    => $tasks,
        ]);
    }

    /**
     * Create follow-up task.
     *
     * SECURITY:
     * Only admin/staff can create follow-up tasks.
     */
    public function store(Request $request, VictimReport $report): JsonResponse
    {
        if (!$this->canManageCases($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can create follow-up tasks.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority'    => ['nullable', 'in:low,medium,high'],
            'status'      => ['nullable', 'in:pending,in_progress,done,cancelled'],
            'due_date'    => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $status = $validated['status'] ?? 'pending';

        $task = CaseFollowUpTask::create([
            'victim_report_id' => $report->id,
            'created_by'       => $request->user()?->id,
            'assigned_to'      => $validated['assigned_to'] ?? null,
            'title'            => $validated['title'],
            'description'      => $validated['description'] ?? null,
            'priority'         => $validated['priority'] ?? 'medium',
            'status'           => $status,
            'due_date'         => $validated['due_date'] ?? null,
            'completed_at'     => $status === 'done' ? now() : null,
        ]);

        $this->syncCaseStatusFromFollowUpTasks($report->id);

        $task->load([
            'creator:id,name,email,phone',
            'assignee:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Follow-up task created successfully.',
            'data'    => $this->transformTask($request, $task),
        ], 201);
    }

    /**
     * Update follow-up task.
     *
     * SECURITY:
     * Only admin/staff can update follow-up tasks.
     */
    public function update(Request $request, CaseFollowUpTask $task): JsonResponse
    {
        if (!$this->canManageCases($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can update follow-up tasks.',
            ], 403);
        }

        $oldVictimReportId = $task->victim_report_id;

        $validator = Validator::make($request->all(), [
            'title'       => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority'    => ['sometimes', 'required', 'in:low,medium,high'],
            'status'      => ['sometimes', 'required', 'in:pending,in_progress,done,cancelled'],
            'due_date'    => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if (isset($validated['status']) && $validated['status'] === 'done') {
            $validated['completed_at'] = now();
        }

        if (isset($validated['status']) && $validated['status'] !== 'done') {
            $validated['completed_at'] = null;
        }

        $task->update($validated);

        if ($oldVictimReportId) {
            $this->syncCaseStatusFromFollowUpTasks((int) $oldVictimReportId);
        }

        $task->load([
            'creator:id,name,email,phone',
            'assignee:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Follow-up task updated successfully.',
            'data'    => $this->transformTask($request, $task),
        ]);
    }

    /**
     * Delete follow-up task.
     *
     * SECURITY:
     * Only admin/staff can delete follow-up tasks.
     */
    public function destroy(Request $request, CaseFollowUpTask $task): JsonResponse
    {
        if (!$this->canManageCases($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can delete follow-up tasks.',
            ], 403);
        }

        $victimReportId = $task->victim_report_id;

        $task->delete();

        if ($victimReportId) {
            $this->syncCaseStatusFromFollowUpTasks((int) $victimReportId);
        }

        return response()->json([
            'success' => true,
            'message' => 'Follow-up task deleted successfully.',
        ]);
    }

    /**
     * Victim can access only own case.
     * Admin/staff can access all cases.
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
     *
     * Supports:
     * - users.role
     * - users.role_slug
     * - users.user_role
     * - users.type
     * - roles relationship with slug/name
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
     * Get role names safely.
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
            /*
             * Keep user as non-admin when role relationship fails.
             */
        }

        return array_values(array_unique(array_filter($roles)));
    }

    /**
     * Sync victim report status using follow-up tasks.
     */
    private function syncCaseStatusFromFollowUpTasks(?int $victimReportId): void
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
        | Do not overwrite final statuses.
        |--------------------------------------------------------------------------
        */
        if (in_array($report->status, ['closed', 'withdrawn', 'rejected'], true)) {
            return;
        }

        $tasks = CaseFollowUpTask::where('victim_report_id', $victimReportId)->get();

        if ($tasks->isEmpty()) {
            return;
        }

        $hasInProgress = $tasks->contains(function ($task) {
            return $task->status === 'in_progress';
        });

        $hasPending = $tasks->contains(function ($task) {
            return $task->status === 'pending';
        });

        $allFinished = $tasks->every(function ($task) {
            return in_array($task->status, ['done', 'cancelled'], true);
        });

        $hasDone = $tasks->contains(function ($task) {
            return $task->status === 'done';
        });

        if ($allFinished && $hasDone) {
            $report->status = 'resolved';
            $report->save();
            return;
        }

        if ($hasInProgress) {
            $report->status = 'in_progress';
            $report->save();
            return;
        }

        if ($hasPending && in_array($report->status, ['submitted', null], true)) {
            $report->status = 'pending';
            $report->save();
        }
    }

    /**
     * Transform follow-up task response.
     *
     * SECURITY:
     * - Admin/staff can see staff email/phone.
     * - Victim can see staff names only, not emails/phones.
     */
    private function transformTask(Request $request, CaseFollowUpTask $task): array
    {
        $isManager = $this->canManageCases($request);

        return [
            'id'               => $task->id,
            'task_code'        => $task->task_code ?? ('T-' . str_pad($task->id, 3, '0', STR_PAD_LEFT)),
            'victim_report_id' => $task->victim_report_id,
            'case_code'        => 'CASE-' . str_pad($task->victim_report_id, 4, '0', STR_PAD_LEFT),

            'title'            => $task->title,
            'description'      => $task->description,
            'priority'         => $task->priority,
            'status'           => $task->status,
            'due_date'         => optional($task->due_date)->format('Y-m-d'),
            'completed_at'     => optional($task->completed_at)->toDateTimeString(),

            'created_by'       => $isManager ? $task->created_by : null,
            'creator'          => $task->creator ? [
                'id'    => $task->creator->id,
                'name'  => $task->creator->name,
                'email' => $isManager ? $task->creator->email : null,
                'phone' => $isManager ? $task->creator->phone : null,
            ] : null,

            'assigned_to'      => $task->assigned_to,
            'assignee'         => $task->assignee ? [
                'id'    => $task->assignee->id,
                'name'  => $task->assignee->name,
                'email' => $isManager ? $task->assignee->email : null,
                'phone' => $isManager ? $task->assignee->phone : null,
            ] : null,

            'created_at'       => optional($task->created_at)->toDateTimeString(),
            'updated_at'       => optional($task->updated_at)->toDateTimeString(),
        ];
    }
}
