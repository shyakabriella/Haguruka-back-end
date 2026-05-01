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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $tasks = $query->get()->map(function ($task) {
            return $this->transformTask($task);
        });

        return response()->json([
            'success' => true,
            'message' => 'Follow-up tasks fetched successfully.',
            'data'    => $tasks,
        ]);
    }

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

        $task = CaseFollowUpTask::create([
            'victim_report_id' => $report->id,
            'created_by'       => $request->user()?->id,
            'assigned_to'      => $validated['assigned_to'] ?? null,
            'title'            => $validated['title'],
            'description'      => $validated['description'] ?? null,
            'priority'         => $validated['priority'] ?? 'medium',
            'status'           => $validated['status'] ?? 'pending',
            'due_date'         => $validated['due_date'] ?? null,
            'completed_at'     => ($validated['status'] ?? null) === 'done' ? now() : null,
        ]);

        $task->load([
            'creator:id,name,email,phone',
            'assignee:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Follow-up task created successfully.',
            'data'    => $this->transformTask($task),
        ], 201);
    }

    public function update(Request $request, CaseFollowUpTask $task): JsonResponse
    {
        if (!$this->canManageCases($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can update follow-up tasks.',
            ], 403);
        }

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

        $task->load([
            'creator:id,name,email,phone',
            'assignee:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Follow-up task updated successfully.',
            'data'    => $this->transformTask($task),
        ]);
    }

    public function destroy(Request $request, CaseFollowUpTask $task): JsonResponse
    {
        if (!$this->canManageCases($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can delete follow-up tasks.',
            ], 403);
        }

        $task->delete();

        return response()->json([
            'success' => true,
            'message' => 'Follow-up task deleted successfully.',
        ]);
    }

    private function canAccessCase(Request $request, VictimReport $report): bool
    {
        if ($this->canManageCases($request)) {
            return true;
        }

        return (int) $report->user_id === (int) $request->user()?->id;
    }

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

    private function transformTask(CaseFollowUpTask $task): array
    {
        return [
            'id'               => $task->id,
            'task_code'        => 'T-' . str_pad($task->id, 3, '0', STR_PAD_LEFT),
            'victim_report_id' => $task->victim_report_id,
            'case_code'        => 'CASE-' . str_pad($task->victim_report_id, 4, '0', STR_PAD_LEFT),

            'title'            => $task->title,
            'description'      => $task->description,
            'priority'         => $task->priority,
            'status'           => $task->status,
            'due_date'         => optional($task->due_date)->format('Y-m-d'),
            'completed_at'     => optional($task->completed_at)->toDateTimeString(),

            'created_by'       => $task->created_by,
            'creator'          => $task->creator ? [
                'id'    => $task->creator->id,
                'name'  => $task->creator->name,
                'email' => $task->creator->email,
                'phone' => $task->creator->phone,
            ] : null,

            'assigned_to'      => $task->assigned_to,
            'assignee'         => $task->assignee ? [
                'id'    => $task->assignee->id,
                'name'  => $task->assignee->name,
                'email' => $task->assignee->email,
                'phone' => $task->assignee->phone,
            ] : null,

            'created_at'       => optional($task->created_at)->toDateTimeString(),
            'updated_at'       => optional($task->updated_at)->toDateTimeString(),
        ];
    }
}