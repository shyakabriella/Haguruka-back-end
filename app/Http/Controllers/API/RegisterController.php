<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegisterController extends BaseController
{
    /**
     * Register API
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
            'password'      => $request->password,
            'status'        => 'active',
            'is_active'     => true,
            'last_login_at' => null,
        ]);

        if (Schema::hasTable('roles') && Schema::hasTable('role_user')) {
            $defaultRole = Role::where('slug', 'haguruka_staff')->first();

            if ($defaultRole) {
                $user->roles()->syncWithoutDetaching([$defaultRole->id]);
            }
        }

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

    /*
    |--------------------------------------------------------------------------
    | Users & Roles API
    |--------------------------------------------------------------------------
    | These are used by your UsersRoles.jsx page.
    |--------------------------------------------------------------------------
    */

    /**
     * GET /api/roles
     */
    public function roles(Request $request): JsonResponse
    {
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

        return $this->sendResponse($users, 'Users fetched successfully.');
    }

    /**
     * POST /api/users
     */
    public function storeUser(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name'      => 'required|string|max:255',
                'email'     => 'nullable|email|max:255|unique:users,email|required_without:phone',
                'phone'     => 'nullable|string|max:20|unique:users,phone|required_without:email',
                'password'  => 'required|string|min:8',

                // Frontend may send role or role_slug
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
            'password'      => $request->password,
            'status'        => $status,
            'is_active'     => $isActive,
            'last_login_at' => null,
        ]);

        $roleSlug = $request->role_slug ?: $request->role ?: 'haguruka_staff';

        $role = Role::where('slug', $roleSlug)->first();

        if ($role) {
            $user->roles()->sync([$role->id]);
        }

        $user->load('roles:id,name,slug');

        return $this->sendResponse($this->formatUserForList($user), 'User created successfully.');
    }

    /**
     * GET /api/users/{id}
     */
    public function showUser(Request $request, $id): JsonResponse
    {
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

                // Frontend may send role or role_slug
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
            $data['password'] = $request->password;
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
            $role = Role::where('slug', $roleSlug)->first();

            if ($role) {
                $user->roles()->sync([$role->id]);
            }
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
        $user = User::find($id);

        if (!$user) {
            return $this->sendError('User not found.', [
                'error' => 'The selected user does not exist.',
            ]);
        }

        if ($request->user() && $request->user()->id === $user->id) {
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

    private function formatUserForAuth(User $user): array
    {
        $primaryRole = $this->getPrimaryRole($user);
        $isAdmin = $this->userIsAdmin($user);

        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'status'        => $user->status,
            'is_active'     => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'roles'         => $user->roles,

            // Easy frontend fields
            'role'          => $primaryRole,
            'role_slug'     => $primaryRole['slug'] ?? null,
            'role_name'     => $primaryRole['name'] ?? null,
            'is_admin'      => $isAdmin,
        ];
    }

    private function formatUserForList(User $user): array
    {
        $primaryRole = $this->getPrimaryRole($user);
        $isAdmin = $this->userIsAdmin($user);

        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'status'        => $user->status,
            'is_active'     => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'created_at'    => $user->created_at,
            'updated_at'    => $user->updated_at,
            'roles'         => $user->roles,

            // Easy frontend fields
            'role'          => $primaryRole,
            'role_slug'     => $primaryRole['slug'] ?? null,
            'role_name'     => $primaryRole['name'] ?? null,
            'is_admin'      => $isAdmin,
        ];
    }

    private function getPrimaryRole(User $user): ?array
    {
        if (!$user->relationLoaded('roles')) {
            $user->load('roles:id,name,slug');
        }

        if (!$user->roles || $user->roles->count() === 0) {
            return null;
        }

        $adminRole = $user->roles->firstWhere('slug', 'admin');
        $role = $adminRole ?: $user->roles->first();

        return [
            'id'   => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
        ];
    }

    private function userIsAdmin(User $user): bool
    {
        if (!$user->relationLoaded('roles')) {
            $user->load('roles:id,name,slug');
        }

        return $user->roles->contains(function ($role) {
            return $role->slug === 'admin';
        });
    }
}