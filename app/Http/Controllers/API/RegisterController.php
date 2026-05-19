<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegisterController extends BaseController
{
    /**
     * Public Register API
     *
     * IMPORTANT:
     * Any public registration from the mobile victim app becomes a VICTIM user.
     * Never register public users as haguruka_staff.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name'     => 'required|string|max:255',
                'email'    => 'nullable|email|max:255|unique:users,email|required_without:phone',
                'phone'    => 'nullable|string|max:20|unique:users,phone|required_without:email',
                'password' => 'required|string|min:8',
            ],
            [
                'email.required_without' => 'Email or phone is required.',
                'phone.required_without' => 'Phone or email is required.',
            ]
        );

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $user = User::create([
            'name'          => $request->name,
            'email'         => $request->email,
            'phone'         => $request->phone,
            'password'      => Hash::make($request->password),
            'status'        => 'active',
            'is_active'     => true,
            'last_login_at' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | SECURITY FIX
        |--------------------------------------------------------------------------
        | Public registration must create a victim account, not a staff account.
        | This prevents a new victim from seeing all system cases.
        |--------------------------------------------------------------------------
        */
        $this->assignSingleRole($user, 'victim', 'Victim');

        $user->load('roles:id,name,slug');

        $success = [
            'token' => $user->createToken('Haguruka')->plainTextToken,
            'user'  => $this->formatUserForAuth($user),
        ];

        return $this->sendResponse($success, 'User registered successfully.');
    }

    /**
     * Login API
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email'    => 'nullable|email|required_without:phone',
                'phone'    => 'nullable|string|required_without:email',
                'password' => 'required|string',
            ],
            [
                'email.required_without' => 'Email or phone is required.',
                'phone.required_without' => 'Phone or email is required.',
            ]
        );

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $loginField = $request->filled('email') ? 'email' : 'phone';

        $credentials = [
            $loginField => $request->$loginField,
            'password'  => $request->password,
        ];

        if (!Auth::attempt($credentials)) {
            return $this->sendError('Unauthorised.', [
                'error' => 'Invalid credentials',
            ]);
        }

        /** @var \App\Models\User $user */
        $user = User::with('roles:id,name,slug')->find(Auth::id());

        if (!$user) {
            Auth::logout();

            return $this->sendError('Unauthorised.', [
                'error' => 'User not found.',
            ]);
        }

        if (!$user->is_active || $user->status !== 'active') {
            Auth::logout();

            return $this->sendError('Account access denied.', [
                'error' => 'Your account is inactive or suspended.',
            ]);
        }

        $user->update([
            'last_login_at' => now(),
        ]);

        $user->refresh();
        $user->load('roles:id,name,slug');

        $success = [
            'token' => $user->createToken('Haguruka')->plainTextToken,
            'user'  => $this->formatUserForAuth($user),
        ];

        return $this->sendResponse($success, 'User login successfully.');
    }

    /**
     * GET /api/me
     *
     * Returns only the currently logged-in user.
     * Victim dashboard must use this endpoint to know who is logged in.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user->load('roles:id,name,slug');

        return $this->sendResponse($this->formatUserForAuth($user), 'Authenticated user fetched successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Users & Roles API
    |--------------------------------------------------------------------------
    | These endpoints are system/admin endpoints.
    | Victims must not access all users or all roles.
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/roles
     */
    public function roles(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfCannotManageUsers($request)) {
            return $deny;
        }

        $roles = Role::query()
            ->select('id', 'name', 'slug', 'description', 'is_active')
            ->orderBy('name')
            ->get();

        return $this->sendResponse($roles, 'Roles fetched successfully.');
    }

    /**
     * GET /api/users
     */
    public function users(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfCannotManageUsers($request)) {
            return $deny;
        }

        $users = User::with('roles:id,name,slug')
            ->select(
                'id',
                'name',
                'email',
                'phone',
                'status',
                'is_active',
                'last_login_at',
                'created_at',
                'updated_at'
            )
            ->latest()
            ->get();

        return $this->sendResponse(
            $users->map(fn (User $user) => $this->formatUserForList($user))->values(),
            'Users fetched successfully.'
        );
    }

    /**
     * POST /api/users
     *
     * Admin/staff creates system users.
     */
    public function storeUser(Request $request): JsonResponse
    {
        if ($deny = $this->denyIfCannotManageUsers($request)) {
            return $deny;
        }

        $validator = Validator::make(
            $request->all(),
            [
                'name'      => 'required|string|max:255',
                'email'     => 'nullable|email|max:255|unique:users,email|required_without:phone',
                'phone'     => 'nullable|string|max:20|unique:users,phone|required_without:email',
                'password'  => 'required|string|min:8',

                'role'      => 'nullable|string|exists:roles,slug',
                'role_slug' => 'nullable|string|exists:roles,slug',

                'status'    => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
                'is_active' => 'nullable|boolean',
            ],
            [
                'email.required_without' => 'Email or phone is required.',
                'phone.required_without' => 'Phone or email is required.',
            ]
        );

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $isActive = $request->has('is_active')
            ? (bool) $request->is_active
            : true;

        $status = $request->status ?: ($isActive ? 'active' : 'inactive');

        $user = User::create([
            'name'          => $request->name,
            'email'         => $request->email,
            'phone'         => $request->phone,
            'password'      => Hash::make($request->password),
            'status'        => $status,
            'is_active'     => $isActive,
            'last_login_at' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Admin-created users
        |--------------------------------------------------------------------------
        | Default to victim if no role is provided, so we never accidentally create
        | a staff account from missing form data.
        |--------------------------------------------------------------------------
        */
        $roleSlug = $request->role_slug ?: $request->role ?: 'victim';
        $roleName = $this->roleNameFromSlug($roleSlug);

        $this->assignSingleRole($user, $roleSlug, $roleName);

        $user->load('roles:id,name,slug');

        return $this->sendResponse($this->formatUserForList($user), 'User created successfully.');
    }

    /**
     * GET /api/users/{id}
     */
    public function showUser(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManageUsers($request)) {
            return $deny;
        }

        $user = User::with('roles:id,name,slug')
            ->select(
                'id',
                'name',
                'email',
                'phone',
                'status',
                'is_active',
                'last_login_at',
                'created_at',
                'updated_at'
            )
            ->find($id);

        if (!$user) {
            return $this->sendError('User not found.', [
                'error' => 'The selected user does not exist.',
            ]);
        }

        return $this->sendResponse($this->formatUserForList($user), 'User fetched successfully.');
    }

    /**
     * PATCH/PUT /api/users/{id}
     */
    public function updateUser(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManageUsers($request)) {
            return $deny;
        }

        $user = User::with('roles:id,name,slug')->find($id);

        if (!$user) {
            return $this->sendError('User not found.', [
                'error' => 'The selected user does not exist.',
            ]);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'name'      => 'sometimes|required|string|max:255',

                'email'     => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($user->id),
                ],

                'phone'     => [
                    'nullable',
                    'string',
                    'max:20',
                    Rule::unique('users', 'phone')->ignore($user->id),
                ],

                'password'  => 'nullable|string|min:8',

                'role'      => 'nullable|string|exists:roles,slug',
                'role_slug' => 'nullable|string|exists:roles,slug',

                'status'    => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
                'is_active' => 'nullable|boolean',
            ]
        );

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $data = [];

        if ($request->has('name')) {
            $data['name'] = $request->name;
        }

        if ($request->has('email')) {
            $data['email'] = $request->email;
        }

        if ($request->has('phone')) {
            $data['phone'] = $request->phone;
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->has('is_active')) {
            $data['is_active'] = (bool) $request->is_active;

            if (!$request->has('status')) {
                $data['status'] = $data['is_active'] ? 'active' : 'inactive';
            }
        }

        if ($request->has('status')) {
            $data['status'] = $request->status;
            $data['is_active'] = $request->status === 'active';
        }

        if (!empty($data)) {
            $user->update($data);
        }

        $roleSlug = $request->role_slug ?: $request->role;

        if ($roleSlug) {
            $this->assignSingleRole($user, $roleSlug, $this->roleNameFromSlug($roleSlug));
        }

        $user->refresh();
        $user->load('roles:id,name,slug');

        return $this->sendResponse($this->formatUserForList($user), 'User updated successfully.');
    }

    /**
     * DELETE /api/users/{id}
     */
    public function deleteUser(Request $request, $id): JsonResponse
    {
        if ($deny = $this->denyIfCannotManageUsers($request)) {
            return $deny;
        }

        $user = User::find($id);

        if (!$user) {
            return $this->sendError('User not found.', [
                'error' => 'The selected user does not exist.',
            ]);
        }

        if ($request->user() && (int) $request->user()->id === (int) $user->id) {
            return $this->sendError('Action not allowed.', [
                'error' => 'You cannot delete your own account while logged in.',
            ]);
        }

        if (Schema::hasTable('role_user')) {
            $user->roles()->detach();
        }

        $user->delete();

        return $this->sendResponse([], 'User deleted successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function denyIfCannotManageUsers(Request $request): ?JsonResponse
    {
        if ($this->canManageUsers($request)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Only admin or Haguruka staff can access users and roles.',
        ], 403);
    }

    private function canManageUsers(Request $request): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        $allowedRoles = [
            'admin',
            'super_admin',
            'haguruka_staff',
        ];

        foreach ($this->getUserRoleSlugs($user) as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    private function getUserRoleSlugs(User $user): array
    {
        if (!$user->relationLoaded('roles')) {
            $user->load('roles:id,name,slug');
        }

        $roles = [];

        if ($user->roles) {
            foreach ($user->roles as $role) {
                if (!empty($role->slug)) {
                    $roles[] = strtolower(trim((string) $role->slug));
                }

                if (!empty($role->name)) {
                    $roles[] = strtolower(trim((string) $role->name));
                }
            }
        }

        foreach (['role', 'role_slug', 'user_role', 'type'] as $field) {
            if (!empty($user->{$field}) && is_string($user->{$field})) {
                $roles[] = strtolower(trim($user->{$field}));
            }
        }

        return array_values(array_unique(array_filter($roles)));
    }

    private function assignSingleRole(User $user, string $slug, string $name): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_user')) {
            return;
        }

        $role = $this->findOrCreateRole($slug, $name);

        if ($role) {
            $user->roles()->sync([$role->id]);
        }
    }

    private function findOrCreateRole(string $slug, string $name): ?Role
    {
        $role = Role::where('slug', $slug)->first();

        if ($role) {
            return $role;
        }

        try {
            $role = new Role();
            $role->name = $name;
            $role->slug = $slug;

            if (Schema::hasColumn('roles', 'description')) {
                $role->description = $name . ' account';
            }

            if (Schema::hasColumn('roles', 'is_active')) {
                $role->is_active = true;
            }

            $role->save();

            return $role;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function roleNameFromSlug(string $slug): string
    {
        return match ($slug) {
            'admin'          => 'Admin',
            'super_admin'    => 'Super Admin',
            'haguruka_staff' => 'Haguruka Staff',
            'staff'          => 'Staff',
            'case_manager'   => 'Case Manager',
            'victim'         => 'Victim',
            default          => ucwords(str_replace('_', ' ', $slug)),
        };
    }

    private function formatUserForAuth(User $user): array
    {
        $primaryRole = $this->getPrimaryRole($user);
        $roleSlug = $primaryRole['slug'] ?? null;

        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'phone'             => $user->phone,
            'status'            => $user->status,
            'is_active'         => $user->is_active,
            'last_login_at'     => optional($user->last_login_at)->toDateTimeString(),
            'roles'             => $user->roles,

            'role'              => $primaryRole,
            'role_slug'         => $roleSlug,
            'role_name'         => $primaryRole['name'] ?? null,

            'is_admin'          => in_array($roleSlug, ['admin', 'super_admin'], true),
            'can_manage_system' => $this->canManageUsersForUser($user),
            'is_victim'         => $roleSlug === 'victim' || !$this->canManageUsersForUser($user),
        ];
    }

    private function formatUserForList(User $user): array
    {
        $primaryRole = $this->getPrimaryRole($user);
        $roleSlug = $primaryRole['slug'] ?? null;

        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'phone'             => $user->phone,
            'status'            => $user->status,
            'is_active'         => $user->is_active,
            'last_login_at'     => optional($user->last_login_at)->toDateTimeString(),
            'created_at'        => optional($user->created_at)->toDateTimeString(),
            'updated_at'        => optional($user->updated_at)->toDateTimeString(),
            'roles'             => $user->roles,

            'role'              => $primaryRole,
            'role_slug'         => $roleSlug,
            'role_name'         => $primaryRole['name'] ?? null,

            'is_admin'          => in_array($roleSlug, ['admin', 'super_admin'], true),
            'can_manage_system' => $this->canManageUsersForUser($user),
            'is_victim'         => $roleSlug === 'victim' || !$this->canManageUsersForUser($user),
        ];
    }

    private function canManageUsersForUser(User $user): bool
    {
        $allowedRoles = [
            'admin',
            'super_admin',
            'haguruka_staff',
        ];

        foreach ($this->getUserRoleSlugs($user) as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return true;
            }
        }

        return false;
    }

    private function getPrimaryRole(User $user): ?array
    {
        if (!$user->relationLoaded('roles')) {
            $user->load('roles:id,name,slug');
        }

        if (!$user->roles || $user->roles->count() === 0) {
            return [
                'id'   => null,
                'name' => 'Victim',
                'slug' => 'victim',
            ];
        }

        $priority = [
            'super_admin',
            'admin',
            'haguruka_staff',
            'staff',
            'case_manager',
            'victim',
        ];

        foreach ($priority as $slug) {
            $role = $user->roles->firstWhere('slug', $slug);

            if ($role) {
                return [
                    'id'   => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ];
            }
        }

        $role = $user->roles->first();

        return [
            'id'   => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
        ];
    }
}