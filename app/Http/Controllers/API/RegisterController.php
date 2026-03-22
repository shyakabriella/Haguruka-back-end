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
            'user'  => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'phone'     => $user->phone,
                'status'    => $user->status,
                'is_active' => $user->is_active,
                'roles'     => $user->roles,
            ],
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
            return $this->sendError('Unauthorised.', ['error' => 'Invalid credentials']);
        }

        /** @var \App\Models\User $user */
        $user = User::with('roles:id,name,slug')->find(Auth::id());

        if (!$user->is_active || $user->status !== 'active') {
            Auth::logout();

            return $this->sendError('Account access denied.', [
                'error' => 'Your account is inactive or suspended.'
            ]);
        }

        $user->update([
            'last_login_at' => now(),
        ]);

        $success = [
            'token' => $user->createToken('Haguruka')->plainTextToken,
            'user'  => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'phone'         => $user->phone,
                'status'        => $user->status,
                'is_active'     => $user->is_active,
                'last_login_at' => $user->last_login_at,
                'roles'         => $user->roles,
            ],
        ];

        return $this->sendResponse($success, 'User login successfully.');
    }
}