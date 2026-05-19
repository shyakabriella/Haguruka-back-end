<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CaseFollowUpTask;
use App\Models\VictimReport;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    /**
     * Generate report/statistics.
     *
     * SECURITY RULE:
     * - Admin / Haguruka staff / case managers can see all cases.
     * - Normal victim users can see ONLY their own submitted cases.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'range'            => ['nullable', 'in:7,30,90,365'],

            // Old names support
            'from'             => ['nullable', 'date', 'before_or_equal:today'],
            'to'               => ['nullable', 'date', 'before_or_equal:today'],

            // New names from React frontend
            'from_date'        => ['nullable', 'date', 'before_or_equal:today'],
            'to_date'          => ['nullable', 'date', 'before_or_equal:today'],

            'report_category'  => ['nullable', 'in:general,individual'],
            'individual_query' => ['nullable', 'string', 'max:255'],

            'report_type'      => ['nullable', 'in:summary,district,case_type,status,channel,urgent,follow_up,appointments'],
            'type'             => ['nullable', 'string'],
            'status'           => ['nullable', 'string'],
            'channel'          => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $range = (int) $request->get('range', 30);

        if (!in_array($range, [7, 30, 90, 365], true)) {
            $range = 30;
        }

        $reportCategory = $request->get('report_category', 'general');
        $reportType = $request->get('report_type', 'summary');

        $toInput = $request->input('to_date') ?: $request->input('to');
        $fromInput = $request->input('from_date') ?: $request->input('from');

        $to = $toInput
            ? Carbon::parse($toInput)->endOfDay()
            : now()->endOfDay();

        $from = $fromInput
            ? Carbon::parse($fromInput)->startOfDay()
            : $to->copy()->subDays(max($range - 1, 0))->startOfDay();

        $today = now()->endOfDay();

        if ($from->gt($today) || $to->gt($today)) {
            return response()->json([
                'success' => false,
                'message' => 'Future dates are not allowed for report generation.',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'success' => false,
                'message' => 'From date cannot be after to date.',
            ], 422);
        }

        $individualQuery = trim((string) $request->get('individual_query', ''));

        if ($reportCategory === 'individual' && $individualQuery === '') {
            return response()->json([
                'success' => false,
                'message' => 'Please provide Case Code, Phone, Name, Email, or National ID for individual report.',
            ], 422);
        }

        $hasTypeFilter = $request->filled('type') && $request->type !== 'all';
        $hasStatusFilter = $request->filled('status') && $request->status !== 'all';
        $hasChannelFilter = $request->filled('channel') && $request->channel !== 'all';
        $hasCaseSpecificFilter = $hasTypeFilter || $hasStatusFilter || $hasChannelFilter || $reportCategory === 'individual';
        $canManageCases = $this->canManageCases($request);

        $caseQuery = VictimReport::query()
            ->with([
                'user:id,name,email,phone',
                'evidences',
            ])
            ->whereBetween('created_at', [$from, $to]);

        /*
        |--------------------------------------------------------------------------
        | Critical privacy scope
        |--------------------------------------------------------------------------
        | This must run before every other report filter, so victim users can never
        | generate statistics from another victim's data.
        |--------------------------------------------------------------------------
        */
        $this->applyCaseVisibilityScope($request, $caseQuery);

        if ($hasTypeFilter) {
            $caseQuery->where('case_type', $request->type);
        }

        if ($hasStatusFilter) {
            $caseQuery->where('status', $request->status);
        }

        if ($hasChannelFilter) {
            $caseQuery->where('input_mode', $request->channel);
        }

        if ($reportCategory === 'individual') {
            $this->applyIndividualFilter($caseQuery, $individualQuery);
        }

        $cases = $caseQuery->latest()->get();

        $caseIds = $cases->pluck('id')->values();

        $followUpTasks = collect();

        if (Schema::hasTable('case_follow_up_tasks')) {
            $followUpQuery = CaseFollowUpTask::query()
                ->with([
                    'victimReport:id,user_id,status,case_type,urgency,input_mode,created_at',
                    'victimReport.user:id,name,email,phone',
                    'creator:id,name,email,phone',
                    'assignee:id,name,email,phone',
                ])
                ->whereBetween('created_at', [$from, $to]);

            if ($caseIds->isNotEmpty()) {
                $followUpQuery->whereIn('victim_report_id', $caseIds);
            } elseif (!$canManageCases || $hasCaseSpecificFilter) {
                /*
                 * Normal victim with no matching own case must get no follow-up rows.
                 * Admin/staff with no case-specific filter may still see global follow-up
                 * tasks in the selected date range.
                 */
                $followUpQuery->whereRaw('1 = 0');
            }

            $followUpTasks = $followUpQuery->latest()->get();
        }

        $appointments = collect();

        if (Schema::hasTable('appointments')) {
            $appointmentQuery = Appointment::query()
                ->with([
                    'victimReport:id,user_id,status,case_type,urgency,input_mode,created_at',
                    'victimReport.user:id,name,email,phone',
                    'assignee:id,name,email,phone',
                    'creator:id,name,email,phone',
                ])
                ->whereBetween('scheduled_at', [$from, $to]);

            if ($caseIds->isNotEmpty()) {
                $appointmentQuery->whereIn('victim_report_id', $caseIds);
            } elseif (!$canManageCases || $hasCaseSpecificFilter) {
                /*
                 * Normal victim with no matching own case must get no appointment rows.
                 */
                $appointmentQuery->whereRaw('1 = 0');
            }

            $appointments = $appointmentQuery->latest('scheduled_at')->get();
        }

        $totalReports = $cases->count();

        $kpis = [
            'total_reports'      => $totalReports,
            'submitted'          => $cases->where('status', 'submitted')->count(),
            'pending'            => $cases->whereIn('status', ['pending', 'under_review'])->count(),
            'in_progress'        => $cases->where('status', 'in_progress')->count(),
            'resolved'           => $cases->whereIn('status', ['resolved', 'closed'])->count(),
            'rejected_withdrawn' => $cases->whereIn('status', ['rejected', 'withdrawn'])->count(),
            'urgent'             => $cases->filter(function ($case) {
                return in_array($case->urgency, ['high', 'urgent'], true);
            })->count(),
            'with_evidence'      => $cases->filter(function ($case) {
                return $case->evidences && $case->evidences->count() > 0;
            })->count(),
            'follow_up_tasks'    => $followUpTasks->count(),
            'appointments'       => $appointments->count(),
        ];

        $breakdowns = [
            'by_status' => $this->countBy($cases, function ($case) {
                return $case->status ?: 'unknown';
            }, $totalReports),

            'by_type' => $this->countBy($cases, function ($case) {
                return $this->caseTypeLabel($case->case_type);
            }, $totalReports),

            'by_channel' => $this->countBy($cases, function ($case) {
                return $this->channelLabel($case->input_mode);
            }, $totalReports),

            'by_urgency' => $this->countBy($cases, function ($case) {
                return $case->urgency ?: 'unknown';
            }, $totalReports),
        ];

        $districtSummary = $this->countBy($cases, function ($case) {
            return $case->district ?? 'Unknown';
        }, $totalReports);

        $trend = $this->buildTrend($cases, $from, $to);

        return response()->json([
            'success' => true,
            'message' => 'Report generated successfully.',
            'data'    => [
                'title'        => $this->reportTitle($reportType, $reportCategory),
                'report_type'  => $reportType,
                'generated_at' => now()->toDateTimeString(),

                'filters'      => [
                    'from'             => $from->toDateString(),
                    'to'               => $to->toDateString(),
                    'range'            => $range,
                    'report_category'  => $reportCategory,
                    'individual_query' => $reportCategory === 'individual' ? $individualQuery : null,
                    'type'             => $request->get('type', 'all'),
                    'status'           => $request->get('status', 'all'),
                    'channel'          => $request->get('channel', 'all'),
                    'visibility_scope' => $canManageCases ? 'all_cases' : 'my_cases_only',
                ],

                'kpis'             => $kpis,
                'breakdowns'       => $breakdowns,
                'district_summary' => $districtSummary,
                'trend'            => $trend,

                'case_rows'        => $cases->map(function ($case) {
                    return $this->transformCaseRow($case);
                })->values(),

                'follow_up_rows'   => $followUpTasks->map(function ($task) {
                    return $this->transformFollowUpRow($task);
                })->values(),

                'appointment_rows' => $appointments->map(function ($appointment) {
                    return $this->transformAppointmentRow($appointment);
                })->values(),

                'notes'            => [
                    'Future dates are not allowed.',
                    'Date range is handled using Carbon startOfDay and endOfDay.',
                    'Normal victims can only generate reports from their own cases.',
                    'Admin, super admin, Haguruka staff, staff, and case manager roles can generate reports from all cases.',
                    'Individual report searches case ID, generated case code, user name, user email, user phone, and national ID only if the column exists.',
                    'District summary uses victim_reports.district when available. If your table does not have district, records may show as Unknown.',
                    'Channel means input_mode from victim_reports.',
                ],
            ],
        ]);
    }

    /**
     * Apply owner visibility on victim report query.
     */
    private function applyCaseVisibilityScope(Request $request, Builder $query): void
    {
        if ($this->canManageCases($request)) {
            return;
        }

        $userId = $request->user()?->id;

        /*
         * Fail closed: if user id is missing, return no records.
         */
        $query->where('user_id', $userId ?: 0);
    }

    private function applyIndividualFilter(Builder $caseQuery, string $individualQuery): void
    {
        $search = trim($individualQuery);
        $like = '%' . $search . '%';

        $digitsOnly = preg_replace('/\D+/', '', $search);
        $possibleId = $digitsOnly !== '' ? (int) ltrim($digitsOnly, '0') : null;

        $victimReportColumns = [
            'case_code',
            'reporter_name',
            'reporter_phone',
            'reporter_email',
            'phone',
            'email',
            'national_id',
            'nid',
            'document_number',
        ];

        $userColumns = [
            'name',
            'email',
            'phone',
            'national_id',
            'nid',
            'document_number',
        ];

        $caseQuery->where(function ($query) use (
            $like,
            $possibleId,
            $victimReportColumns,
            $userColumns
        ) {
            $hasCondition = false;

            if ($possibleId && $possibleId > 0) {
                $query->where('id', $possibleId);
                $hasCondition = true;
            }

            foreach ($victimReportColumns as $column) {
                if (!Schema::hasColumn('victim_reports', $column)) {
                    continue;
                }

                if ($hasCondition) {
                    $query->orWhere($column, 'LIKE', $like);
                } else {
                    $query->where($column, 'LIKE', $like);
                    $hasCondition = true;
                }
            }

            $availableUserColumns = array_values(array_filter($userColumns, function ($column) {
                return Schema::hasColumn('users', $column);
            }));

            if (!empty($availableUserColumns)) {
                $userFilter = function ($userQuery) use ($like, $availableUserColumns) {
                    foreach ($availableUserColumns as $index => $column) {
                        if ($index === 0) {
                            $userQuery->where($column, 'LIKE', $like);
                        } else {
                            $userQuery->orWhere($column, 'LIKE', $like);
                        }
                    }
                };

                if ($hasCondition) {
                    $query->orWhereHas('user', $userFilter);
                } else {
                    $query->whereHas('user', $userFilter);
                }
            }
        });
    }

    private function buildTrend(Collection $cases, Carbon $from, Carbon $to): array
    {
        $days = max(0, $from->diffInDays($to));

        $grouped = $cases->groupBy(function ($case) {
            return Carbon::parse($case->created_at)->toDateString();
        });

        $labels = [];
        $values = [];

        for ($i = 0; $i <= $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();

            $labels[] = $date;
            $values[] = isset($grouped[$date]) ? $grouped[$date]->count() : 0;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    private function countBy(Collection $collection, callable $callback, int $total): array
    {
        return $collection
            ->groupBy($callback)
            ->map(function ($items, $name) use ($total) {
                $count = $items->count();

                return [
                    'name'       => (string) $name,
                    'count'      => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->toArray();
    }

    private function transformCaseRow(VictimReport $case): array
    {
        $user = $case->user;

        return [
            'id'             => $case->id,
            'case_code'      => 'CASE-' . str_pad($case->id, 4, '0', STR_PAD_LEFT),
            'user_id'        => $case->user_id,
            'reporter_name'  => $user?->name ?? 'Anonymous',
            'reporter_phone' => $user?->phone,
            'reporter_email' => $user?->email,
            'case_type'      => $this->caseTypeLabel($case->case_type),
            'raw_case_type'  => $case->case_type,
            'status'         => $case->status,
            'urgency'        => $case->urgency,
            'channel'        => $this->channelLabel($case->input_mode),
            'district'       => $case->district ?? 'Unknown',
            'has_evidence'   => $case->evidences && $case->evidences->count() > 0,
            'evidence_count' => $case->evidences ? $case->evidences->count() : 0,
            'created_at'     => optional($case->created_at)->toDateTimeString(),
        ];
    }

    private function transformFollowUpRow(CaseFollowUpTask $task): array
    {
        return [
            'id'           => $task->id,
            'task_code'    => 'T-' . str_pad($task->id, 3, '0', STR_PAD_LEFT),
            'case_code'    => 'CASE-' . str_pad($task->victim_report_id, 4, '0', STR_PAD_LEFT),
            'title'        => $task->title,
            'priority'     => $task->priority,
            'status'       => $task->status,
            'assigned_to'  => $task->assignee?->name ?? 'Unassigned',
            'created_by'   => $task->creator?->name ?? 'Unknown',
            'due_date'     => optional($task->due_date)->format('Y-m-d'),
            'completed_at' => optional($task->completed_at)->toDateTimeString(),
            'created_at'   => optional($task->created_at)->toDateTimeString(),
        ];
    }

    private function transformAppointmentRow(Appointment $appointment): array
    {
        return [
            'id'               => $appointment->id,
            'appointment_code' => 'APT-' . str_pad($appointment->id, 4, '0', STR_PAD_LEFT),
            'case_code'        => $appointment->victim_report_id
                ? 'CASE-' . str_pad($appointment->victim_report_id, 4, '0', STR_PAD_LEFT)
                : 'No Case',
            'client_name'      => $appointment->client_name ?: $appointment->victimReport?->user?->name ?: 'Anonymous',
            'type'             => $this->appointmentTypeLabel($appointment->appointment_type),
            'status'           => $appointment->status,
            'district'         => $appointment->district ?? 'Unknown',
            'assigned_to'      => $appointment->assignee?->name ?? 'Unassigned',
            'scheduled_at'     => optional($appointment->scheduled_at)->toDateTimeString(),
            'completed_at'     => optional($appointment->completed_at)->toDateTimeString(),
            'cancelled_at'     => optional($appointment->cancelled_at)->toDateTimeString(),
        ];
    }

    private function reportTitle(string $type, string $category = 'general'): string
    {
        $title = match ($type) {
            'district'     => 'District Summary Report',
            'case_type'    => 'Case Type Report',
            'status'       => 'Case Status Report',
            'channel'      => 'Reporting Channel Report',
            'urgent'       => 'Urgent Cases Report',
            'follow_up'    => 'Follow-Up Tasks Report',
            'appointments' => 'Appointments Report',
            default        => 'General Summary Report',
        };

        if ($category === 'individual') {
            return 'Individual ' . $title;
        }

        return 'General ' . $title;
    }

    private function caseTypeLabel(?string $value): string
    {
        return match ($value) {
            'physical'  => 'Physical Abuse',
            'sexual'    => 'Sexual Abuse',
            'emotional' => 'Emotional Abuse',
            'economic'  => 'Economic Abuse',
            'child'     => 'Child Abuse',
            'emergency' => 'Emergency',
            'other'     => 'Other',
            default     => $value ?: 'Unknown',
        };
    }

    private function channelLabel(?string $value): string
    {
        return match ($value) {
            'text'            => 'Text',
            'media'           => 'Media',
            'audio'           => 'Audio',
            'quick_emergency' => 'Quick Emergency',
            'web'             => 'Web',
            'mobile_app'      => 'Mobile App',
            default           => $value ?: 'Unknown',
        };
    }

    private function appointmentTypeLabel(?string $value): string
    {
        return match ($value) {
            'phone_call'      => 'Phone Call',
            'in_person'       => 'In-Person Meeting',
            'isange_referral' => 'Isange Referral',
            'police_referral' => 'Police Referral',
            default           => $value ?: 'Unknown',
        };
    }

    /**
     * Admin/staff permission.
     *
     * Supports:
     * - users.role / role_slug / user_role / type
     * - role object
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

        $roles = $this->getUserRoleSlugs($user);

        foreach ($roles as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }

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
             * If role lookup fails, keep the user as a normal victim.
             */
        }

        return array_values(array_unique(array_filter($roles)));
    }
}