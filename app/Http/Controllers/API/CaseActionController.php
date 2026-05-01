<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\VictimReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class CaseActionController extends Controller
{
    public function withdraw(Request $request, VictimReport $report): JsonResponse
    {
        if (!$this->canAccessCase($request, $report)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to withdraw this case.',
            ], 403);
        }

        if ($this->isFinalized($report->status)) {
            return response()->json([
                'success' => false,
                'message' => 'This case is already finalized and cannot be withdrawn.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required_without:motif', 'nullable', 'string', 'max:2000'],
            'motif'  => ['required_without:reason', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide Reason / Motif.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $reason = $request->input('reason') ?: $request->input('motif');

        $report->status = 'withdrawn';

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

        $report->save();

        $report->loadMissing([
            'user:id,name,email,phone',
            'evidences',
            'withdrawnBy:id,name,email,phone',
            'closedBy:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Case withdrawn successfully.',
            'data'    => $this->transformCase($report),
        ]);
    }

    public function close(Request $request, VictimReport $report): JsonResponse
    {
        if (!$this->canAccessCase($request, $report)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to close this case.',
            ], 403);
        }

        if ($this->isFinalized($report->status)) {
            return response()->json([
                'success' => false,
                'message' => 'This case is already finalized and cannot be closed.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required_without:motif', 'nullable', 'string', 'max:2000'],
            'motif'  => ['required_without:reason', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide Reason / Motif.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $reason = $request->input('reason') ?: $request->input('motif');

        $report->status = 'closed';

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

        $report->save();

        $report->loadMissing([
            'user:id,name,email,phone',
            'evidences',
            'withdrawnBy:id,name,email,phone',
            'closedBy:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Case closed successfully.',
            'data'    => $this->transformCase($report),
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

    private function isFinalized(?string $status): bool
    {
        return in_array($status, ['closed', 'withdrawn', 'rejected'], true);
    }

    private function transformCase(VictimReport $report): array
    {
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

            'reporter_user' => $report->user ? [
                'id'    => $report->user->id,
                'name'  => $report->user->name,
                'email' => $report->user->email,
                'phone' => $report->user->phone,
            ] : null,

            'evidences' => $report->relationLoaded('evidences')
                ? $report->evidences->map(function ($evidence) {
                    return [
                        'id'        => $evidence->id,
                        'file_name' => $evidence->file_name ?? null,
                        'file_type' => $evidence->file_type ?? null,
                        'file_size' => $evidence->file_size ?? null,
                        'file_url'  => $evidence->file_url ?? null,
                    ];
                })->values()
                : [],

            'created_at' => optional($report->created_at)->toDateTimeString(),
            'updated_at' => optional($report->updated_at)->toDateTimeString(),
        ];
    }
}