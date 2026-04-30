<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\VictimReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class VictimReportController extends Controller
{
    /**
     * Store a newly created victim report.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'language'        => ['nullable', 'string', 'max:10'],
            'quick_emergency' => ['nullable', 'boolean'],

            'reporter_role'   => [
                'nullable',
                'in:victim,witness,family,other,someone_else,community_leader',
            ],

            'urgency'         => ['nullable', 'in:urgent,support'],
            'case_type'       => ['nullable', 'in:physical,sexual,emotional,economic,child,other'],
            'input_mode'      => ['nullable', 'in:text,media,audio'],
            'details'         => ['nullable', 'string'],
            'latitude'        => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'       => ['nullable', 'numeric', 'between:-180,180'],

            'evidence'        => ['nullable', 'array'],
            'evidence.*'      => [
                'file',
                'max:51200',
                'mimes:jpg,jpeg,png,webp,pdf,mp4,mov,mp3,m4a,wav,doc,docx,txt',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $isQuickEmergency = filter_var(
            $request->input('quick_emergency', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (
            !$isQuickEmergency &&
            empty($validated['details']) &&
            !$request->hasFile('evidence')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide case details or at least one evidence file.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $report = VictimReport::create([
                'user_id'       => $request->user()?->id,
                'language'      => $validated['language'] ?? null,

                'reporter_role' => $isQuickEmergency
                    ? 'victim'
                    : ($validated['reporter_role'] ?? null),

                'urgency'       => $isQuickEmergency
                    ? 'urgent'
                    : ($validated['urgency'] ?? null),

                'case_type'     => $isQuickEmergency
                    ? 'other'
                    : ($validated['case_type'] ?? null),

                'input_mode'    => $isQuickEmergency
                    ? 'text'
                    : ($validated['input_mode'] ?? null),

                'details'       => $isQuickEmergency
                    ? ($validated['details'] ?? 'Quick emergency alert submitted from mobile dashboard. Victim may be in immediate danger and needs urgent support.')
                    : ($validated['details'] ?? null),

                'latitude'      => $validated['latitude'] ?? null,
                'longitude'     => $validated['longitude'] ?? null,
                'status'        => 'submitted',
            ]);

            if ($request->hasFile('evidence')) {
                $files = $request->file('evidence');

                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    $path = $file->store('victim-reports/evidence', 'public');

                    $report->evidences()->create([
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'source'    => $isQuickEmergency ? 'quick-emergency-upload' : 'mobile-upload',
                    ]);
                }
            }

            DB::commit();

            $report->load([
                'evidences',
                'user:id,name,email,phone,status,is_active',
            ]);

            return response()->json([
                'success' => true,
                'message' => $isQuickEmergency
                    ? 'Emergency case submitted successfully.'
                    : 'Case submitted successfully.',
                'data'    => $this->transformReport($report),
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit case.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Quick emergency report endpoint.
     */
    public function quickEmergency(Request $request): JsonResponse
    {
        $payload = array_merge($request->all(), [
            'quick_emergency' => true,
            'reporter_role'   => 'victim',
            'urgency'         => 'urgent',
            'case_type'       => 'other',
            'input_mode'      => 'text',
            'details'         => $request->input(
                'details',
                'Quick emergency alert submitted from mobile dashboard. Victim may be in immediate danger and needs urgent support.'
            ),
        ]);

        $newRequest = Request::create(
            $request->path(),
            $request->method(),
            $payload,
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all()
        );

        $newRequest->setUserResolver(function () use ($request) {
            return $request->user();
        });

        return $this->store($newRequest);
    }

    /**
     * Display listing of victim reports.
     *
     * admin / haguruka_staff can see all cases.
     * Normal users can only see their own cases.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);

        if ($perPage < 1) {
            $perPage = 10;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $query = VictimReport::with([
            'evidences',
            'user:id,name,email,phone,status,is_active',
        ]);

        if (!$this->userCanSeeAllCases($request)) {
            $query->where('user_id', $request->user()?->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('urgency')) {
            $query->where('urgency', $request->urgency);
        }

        if ($request->filled('case_type')) {
            $query->where('case_type', $request->case_type);
        }

        if ($request->filled('reporter_role')) {
            $query->where('reporter_role', $request->reporter_role);
        }

        if ($request->filled('language')) {
            $query->where('language', $request->language);
        }

        if ($request->filled('q')) {
            $search = trim($request->q);

            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('details', 'LIKE', "%{$search}%")
                    ->orWhere('status', 'LIKE', "%{$search}%")
                    ->orWhere('case_type', 'LIKE', "%{$search}%")
                    ->orWhere('urgency', 'LIKE', "%{$search}%")
                    ->orWhere('reporter_role', 'LIKE', "%{$search}%")
                    ->orWhere('input_mode', 'LIKE', "%{$search}%")
                    ->orWhere('language', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery
                            ->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        $reports = $query
            ->latest()
            ->paginate($perPage);

        $reports->getCollection()->transform(function ($report) {
            return $this->transformReport($report);
        });

        return response()->json([
            'success' => true,
            'message' => 'Cases fetched successfully.',
            'data'    => $reports,
        ]);
    }

    /**
     * Display specified victim report.
     *
     * admin / haguruka_staff can view any case.
     * Normal users can only view their own case.
     */
    public function show($id): JsonResponse
    {
        $request = request();

        $query = VictimReport::with([
            'evidences',
            'user:id,name,email,phone,status,is_active',
        ]);

        if (!$this->userCanSeeAllCases($request)) {
            $query->where('user_id', $request->user()?->id);
        }

        $report = $query->find($id);

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Case not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Case fetched successfully.',
            'data'    => $this->transformReport($report),
        ]);
    }

    /**
     * Check if logged-in user can view all cases.
     */
    private function userCanSeeAllCases(Request $request): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        $roleSlugs = $this->getUserRoleSlugs($user);

        return in_array('admin', $roleSlugs, true)
            || in_array('haguruka_staff', $roleSlugs, true);
    }

    /**
     * Get logged-in user's role slugs safely.
     */
    private function getUserRoleSlugs($user): array
    {
        if (!method_exists($user, 'roles')) {
            return [];
        }

        if (!$user->relationLoaded('roles')) {
            $user->load('roles:id,name,slug');
        }

        return $user->roles
            ? $user->roles->pluck('slug')->filter()->values()->toArray()
            : [];
    }

    /**
     * Transform report response.
     */
    private function transformReport(VictimReport $report): array
    {
        $user = $report->user;

        return [
            'id'            => $report->id,
            'user_id'       => $report->user_id,

            /*
            |--------------------------------------------------------------------------
            | Logged-in reporter / victim information
            |--------------------------------------------------------------------------
            */
            'reporter_user' => $user ? [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'phone'     => $user->phone,
                'status'    => $user->status,
                'is_active' => $user->is_active,
            ] : null,

            'reporter_name'  => $user?->name,
            'reporter_email' => $user?->email,
            'reporter_phone' => $user?->phone,

            /*
            |--------------------------------------------------------------------------
            | Case information
            |--------------------------------------------------------------------------
            */
            'language'      => $report->language,
            'reporter_role' => $report->reporter_role,
            'urgency'       => $report->urgency,
            'case_type'     => $report->case_type,
            'input_mode'    => $report->input_mode,

            'details'       => $report->details,
            'latitude'      => $report->latitude,
            'longitude'     => $report->longitude,
            'status'        => $report->status,

            /*
            |--------------------------------------------------------------------------
            | Evidence files
            |--------------------------------------------------------------------------
            */
            'evidences'     => $report->evidences->map(function ($item) {
                return [
                    'id'        => $item->id,
                    'file_name' => $item->file_name,
                    'file_type' => $item->file_type,
                    'file_size' => $item->file_size,
                    'source'    => $item->source,
                    'file_url'  => $item->file_url ?? Storage::disk('public')->url($item->file_path),
                ];
            })->values(),

            'created_at'    => $report->created_at,
            'updated_at'    => $report->updated_at,
        ];
    }
}