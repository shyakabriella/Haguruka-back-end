<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()
            ->withCount('servicePoints')
            ->with(['servicePoints' => function ($q) {
                $q->latest();
            }])
            ->latest();

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($subQuery) use ($q) {
                $subQuery
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('type', 'like', "%{$q}%")
                    ->orWhere('district', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhereHas('servicePoints', function ($spQuery) use ($q) {
                        $spQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('district', 'like', "%{$q}%")
                            ->orWhere('sector', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = (int) $request->get('per_page', 50);
        $perPage = $perPage > 0 ? min($perPage, 100) : 50;

        $organizations = $query->paginate($perPage);

        $organizations->getCollection()->transform(function ($organization) {
            return $this->transformOrganization($organization);
        });

        return response()->json([
            'success' => true,
            'message' => 'Organizations fetched successfully.',
            'data'    => $organizations,
        ]);
    }

    public function show(Organization $organization): JsonResponse
    {
        $organization->load(['servicePoints' => function ($q) {
            $q->latest();
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Organization fetched successfully.',
            'data'    => $this->transformOrganization($organization),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canManageDirectory($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can create organizations.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name'     => ['required', 'string', 'max:255'],
            'type'     => ['required', 'in:haguruka,police,health,local_authority,ngo'],
            'district' => ['nullable', 'string', 'max:255'],
            'phone'    => ['nullable', 'string', 'max:50'],
            'email'    => ['nullable', 'email', 'max:255'],
            'status'   => ['nullable', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $organization = Organization::create([
            'name'     => $validated['name'],
            'type'     => $validated['type'],
            'district' => $validated['district'] ?? null,
            'phone'    => $validated['phone'] ?? null,
            'email'    => $validated['email'] ?? null,
            'status'   => $validated['status'] ?? 'active',
        ]);

        $organization->load('servicePoints');

        return response()->json([
            'success' => true,
            'message' => 'Organization created successfully.',
            'data'    => $this->transformOrganization($organization),
        ], 201);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        if (!$this->canManageDirectory($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can update organizations.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name'     => ['sometimes', 'required', 'string', 'max:255'],
            'type'     => ['sometimes', 'required', 'in:haguruka,police,health,local_authority,ngo'],
            'district' => ['nullable', 'string', 'max:255'],
            'phone'    => ['nullable', 'string', 'max:50'],
            'email'    => ['nullable', 'email', 'max:255'],
            'status'   => ['sometimes', 'required', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $organization->update($validator->validated());
        $organization->load('servicePoints');

        return response()->json([
            'success' => true,
            'message' => 'Organization updated successfully.',
            'data'    => $this->transformOrganization($organization),
        ]);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        if (!$this->canManageDirectory($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can delete organizations.',
            ], 403);
        }

        $organization->delete();

        return response()->json([
            'success' => true,
            'message' => 'Organization deleted successfully.',
        ]);
    }

    private function canManageDirectory(Request $request): bool
    {
        $user = $request->user();

        if (!$user || !method_exists($user, 'roles')) {
            return false;
        }

        $slugs = $user->roles()->pluck('slug')->toArray();

        return in_array('admin', $slugs, true)
            || in_array('haguruka_staff', $slugs, true);
    }

    private function transformOrganization(Organization $organization): array
    {
        return [
            'id'                   => $organization->id,
            'name'                 => $organization->name,
            'type'                 => $organization->type,
            'district'             => $organization->district,
            'phone'                => $organization->phone,
            'email'                => $organization->email,
            'status'               => $organization->status,
            'service_points_count' => $organization->service_points_count
                ?? $organization->servicePoints()->count(),

            'service_points'       => $organization->relationLoaded('servicePoints')
                ? $organization->servicePoints->map(function ($sp) {
                    return [
                        'id'              => $sp->id,
                        'organization_id' => $sp->organization_id,
                        'name'            => $sp->name,
                        'district'        => $sp->district,
                        'sector'          => $sp->sector,
                        'phone'           => $sp->phone,
                        'email'           => $sp->email,
                        'latitude'        => $sp->latitude,
                        'longitude'       => $sp->longitude,
                        'gps'             => $sp->latitude && $sp->longitude
                            ? $sp->latitude . ', ' . $sp->longitude
                            : '',
                        'status'          => $sp->status,
                        'created_at'      => optional($sp->created_at)->toDateTimeString(),
                        'updated_at'      => optional($sp->updated_at)->toDateTimeString(),
                    ];
                })->values()
                : [],

            'created_at'           => optional($organization->created_at)->toDateTimeString(),
            'updated_at'           => optional($organization->updated_at)->toDateTimeString(),
        ];
    }
}