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
    /**
     * Victim withdraws own case.
     * Admin/staff can withdraw any case.
     */
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

    /**
     * Victim closes own case.
     * Admin/staff can close any case.
     */
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

    /**
     * Victim can access only own case.
     * Admin/staff can access all.
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
     * Admin/staff permission.
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

        $userRoles = $this->getUserRoleSlugs($user);

        foreach ($userRoles as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get role slugs/names from user model safely.
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
            // Ignore role lookup errors and keep user as non-admin.
        }

        return array_values(array_unique(array_filter($roles)));
    }

    private function isFinalized(?string $status): bool
    {
        return in_array(strtolower((string) $status), ['closed', 'withdrawn', 'rejected'], true);
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
            'withdrawn_at'    => $this->formatDateTime($report->withdrawn_at ?? null),
            'withdrawn_by'    => $report->withdrawn_by ?? null,
            'withdrawn_by_user' => $report->withdrawnBy ? [
                'id'    => $report->withdrawnBy->id,
                'name'  => $report->withdrawnBy->name,
                'email' => $report->withdrawnBy->email,
                'phone' => $report->withdrawnBy->phone,
            ] : null,

            'closed_reason' => $report->closed_reason ?? null,
            'closed_at'     => $this->formatDateTime($report->closed_at ?? null),
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

            'created_at' => $this->formatDateTime($report->created_at),
            'updated_at' => $this->formatDateTime($report->updated_at),
        ];
    }

    /**
     * Format datetime safely.
     */
    private function formatDateTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            if ($value instanceof \Carbon\CarbonInterface) {
                return $value->toDateTimeString();
            }

            return \Carbon\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}