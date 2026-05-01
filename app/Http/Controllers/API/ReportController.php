<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CaseFollowUpTask;
use App\Models\VictimReport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        if (!$this->canViewReports($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can view reports.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'range'       => ['nullable', 'in:7,30,90,365'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date'],
            'report_type' => ['nullable', 'in:summary,district,case_type,status,channel,urgent,follow_up,appointments'],
            'type'        => ['nullable', 'string'],
            'status'      => ['nullable', 'string'],
            'channel'     => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $range = (int) $request->get('range', 30);
        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : $to->copy()->subDays($range)->startOfDay();

        $reportType = $request->get('report_type', 'summary');

        $caseQuery = VictimReport::query()
            ->with([
                'user:id,name,email,phone',
                'evidences',
            ])
            ->whereBetween('created_at', [$from, $to]);

        if ($request->filled('type') && $request->type !== 'all') {
            $caseQuery->where('case_type', $request->type);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $caseQuery->where('status', $request->status);
        }

        if ($request->filled('channel') && $request->channel !== 'all') {
            $caseQuery->where('input_mode', $request->channel);
        }

        $cases = $caseQuery->latest()->get();

        $caseIds = $cases->pluck('id')->values();

        $followUpTasks = collect();

        if (Schema::hasTable('case_follow_up_tasks')) {
            $followUpQuery = CaseFollowUpTask::query()
                ->with([
                    'victimReport:id,status,case_type,urgency,input_mode,created_at',
                    'creator:id,name,email,phone',
                    'assignee:id,name,email,phone',
                ])
                ->whereBetween('created_at', [$from, $to]);

            if ($caseIds->isNotEmpty()) {
                $followUpQuery->whereIn('victim_report_id', $caseIds);
            }

            $followUpTasks = $followUpQuery->latest()->get();
        }

        $appointments = collect();

        if (Schema::hasTable('appointments')) {
            $appointmentQuery = Appointment::query()
                ->with([
                    'victimReport:id,status,case_type,urgency,input_mode,created_at',
                    'assignee:id,name,email,phone',
                    'creator:id,name,email,phone',
                ])
                ->whereBetween('scheduled_at', [$from, $to]);

            if ($caseIds->isNotEmpty()) {
                $appointmentQuery->where(function ($query) use ($caseIds) {
                    $query
                        ->whereIn('victim_report_id', $caseIds)
                        ->orWhereNull('victim_report_id');
                });
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
                'title'       => $this->reportTitle($reportType),
                'report_type' => $reportType,
                'generated_at'=> now()->toDateTimeString(),

                'filters'     => [
                    'from'    => $from->toDateString(),
                    'to'      => $to->toDateString(),
                    'range'   => $range,
                    'type'    => $request->get('type', 'all'),
                    'status'  => $request->get('status', 'all'),
                    'channel' => $request->get('channel', 'all'),
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
                    'District summary uses victim_reports.district when available. If your table does not have district, records may show as Unknown.',
                    'Channel means input_mode from victim_reports.',
                ],
            ],
        ]);
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
            'id'          => $task->id,
            'task_code'   => 'T-' . str_pad($task->id, 3, '0', STR_PAD_LEFT),
            'case_code'   => 'CASE-' . str_pad($task->victim_report_id, 4, '0', STR_PAD_LEFT),
            'title'       => $task->title,
            'priority'    => $task->priority,
            'status'      => $task->status,
            'assigned_to' => $task->assignee?->name ?? 'Unassigned',
            'created_by'  => $task->creator?->name ?? 'Unknown',
            'due_date'    => optional($task->due_date)->format('Y-m-d'),
            'completed_at'=> optional($task->completed_at)->toDateTimeString(),
            'created_at'  => optional($task->created_at)->toDateTimeString(),
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

    private function reportTitle(string $type): string
    {
        return match ($type) {
            'district'     => 'District Summary Report',
            'case_type'    => 'Case Type Report',
            'status'       => 'Case Status Report',
            'channel'      => 'Reporting Channel Report',
            'urgent'       => 'Urgent Cases Report',
            'follow_up'    => 'Follow-Up Tasks Report',
            'appointments' => 'Appointments Report',
            default        => 'General Summary Report',
        };
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

    private function canViewReports(Request $request): bool
    {
        $user = $request->user();

        if (!$user || !method_exists($user, 'roles')) {
            return false;
        }

        $slugs = $user->roles()->pluck('slug')->toArray();

        return in_array('admin', $slugs, true)
            || in_array('haguruka_staff', $slugs, true);
    }
}