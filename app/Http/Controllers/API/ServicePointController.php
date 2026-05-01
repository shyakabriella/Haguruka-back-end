<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ServicePoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServicePointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServicePoint::query()
            ->with('organization:id,name,type,district,phone,email,status')
            ->latest();

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->filled('district')) {
            $query->where('district', $request->district);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $q = $request->q;

            $query->where(function ($subQuery) use ($q) {
                $subQuery
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('district', 'like', "%{$q}%")
                    ->orWhere('sector', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhereHas('organization', function ($orgQuery) use ($q) {
                        $orgQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('type', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = (int) $request->get('per_page', 100);
        $perPage = $perPage > 0 ? min($perPage, 100) : 100;

        $servicePoints = $query->paginate($perPage);

        $servicePoints->getCollection()->transform(function ($servicePoint) {
            return $this->transformServicePoint($servicePoint);
        });

        return response()->json([
            'success' => true,
            'message' => 'Service points fetched successfully.',
            'data'    => $servicePoints,
        ]);
    }

    public function show(ServicePoint $servicePoint): JsonResponse
    {
        $servicePoint->load('organization:id,name,type,district,phone,email,status');

        return response()->json([
            'success' => true,
            'message' => 'Service point fetched successfully.',
            'data'    => $this->transformServicePoint($servicePoint),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canManageDirectory($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can create service points.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'organization_id' => ['required', 'exists:organizations,id'],
            'name'            => ['required', 'string', 'max:255'],
            'district'        => ['nullable', 'string', 'max:255'],
            'sector'          => ['nullable', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:255'],
            'latitude'        => ['nullable', 'numeric'],
            'longitude'       => ['nullable', 'numeric'],
            'gps'             => ['nullable', 'string', 'max:100'],
            'status'          => ['nullable', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        [$latitude, $longitude] = $this->resolveGps($validated);

        $servicePoint = ServicePoint::create([
            'organization_id' => $validated['organization_id'],
            'name'            => $validated['name'],
            'district'        => $validated['district'] ?? null,
            'sector'          => $validated['sector'] ?? null,
            'phone'           => $validated['phone'] ?? null,
            'email'           => $validated['email'] ?? null,
            'latitude'        => $latitude,
            'longitude'       => $longitude,
            'status'          => $validated['status'] ?? 'active',
        ]);

        $servicePoint->load('organization:id,name,type,district,phone,email,status');

        return response()->json([
            'success' => true,
            'message' => 'Service point created successfully.',
            'data'    => $this->transformServicePoint($servicePoint),
        ], 201);
    }

    public function update(Request $request, ServicePoint $servicePoint): JsonResponse
    {
        if (!$this->canManageDirectory($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can update service points.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'organization_id' => ['sometimes', 'required', 'exists:organizations,id'],
            'name'            => ['sometimes', 'required', 'string', 'max:255'],
            'district'        => ['nullable', 'string', 'max:255'],
            'sector'          => ['nullable', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:255'],
            'latitude'        => ['nullable', 'numeric'],
            'longitude'       => ['nullable', 'numeric'],
            'gps'             => ['nullable', 'string', 'max:100'],
            'status'          => ['sometimes', 'required', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if (isset($validated['gps']) || isset($validated['latitude']) || isset($validated['longitude'])) {
            [$latitude, $longitude] = $this->resolveGps($validated);

            $validated['latitude'] = $latitude;
            $validated['longitude'] = $longitude;
        }

        unset($validated['gps']);

        $servicePoint->update($validated);
        $servicePoint->load('organization:id,name,type,district,phone,email,status');

        return response()->json([
            'success' => true,
            'message' => 'Service point updated successfully.',
            'data'    => $this->transformServicePoint($servicePoint),
        ]);
    }

    public function destroy(Request $request, ServicePoint $servicePoint): JsonResponse
    {
        if (!$this->canManageDirectory($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only admin or Haguruka staff can delete service points.',
            ], 403);
        }

        $servicePoint->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service point deleted successfully.',
        ]);
    }

    private function resolveGps(array $data): array
    {
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;

        if ((!$latitude || !$longitude) && !empty($data['gps'])) {
            $parts = explode(',', $data['gps']);

            if (count($parts) >= 2) {
                $latitude = trim($parts[0]);
                $longitude = trim($parts[1]);
            }
        }

        return [
            $latitude !== null && $latitude !== '' ? $latitude : null,
            $longitude !== null && $longitude !== '' ? $longitude : null,
        ];
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

    private function transformServicePoint(ServicePoint $servicePoint): array
    {
        return [
            'id'              => $servicePoint->id,
            'organization_id' => $servicePoint->organization_id,

            'organization'    => $servicePoint->organization ? [
                'id'       => $servicePoint->organization->id,
                'name'     => $servicePoint->organization->name,
                'type'     => $servicePoint->organization->type,
                'district' => $servicePoint->organization->district,
                'phone'    => $servicePoint->organization->phone,
                'email'    => $servicePoint->organization->email,
                'status'   => $servicePoint->organization->status,
            ] : null,

            'name'            => $servicePoint->name,
            'district'        => $servicePoint->district,
            'sector'          => $servicePoint->sector,
            'phone'           => $servicePoint->phone,
            'email'           => $servicePoint->email,
            'latitude'        => $servicePoint->latitude,
            'longitude'       => $servicePoint->longitude,
            'gps'             => $servicePoint->latitude && $servicePoint->longitude
                ? $servicePoint->latitude . ', ' . $servicePoint->longitude
                : '',
            'status'          => $servicePoint->status,

            'created_at'      => optional($servicePoint->created_at)->toDateTimeString(),
            'updated_at'      => optional($servicePoint->updated_at)->toDateTimeString(),
        ];
    }
}