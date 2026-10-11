<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Two session strategies on one contract:
 *  - Web SPA (same origin): omit device_name → Sanctum cookie session.
 *  - Mobile apps: send device_name → a bearer token is returned once; store it in the
 *    iOS Keychain / Android Keystore, never in plain storage.
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = new User(Arr::only($data, ['name', 'email', 'phone', 'password', 'city']));
        $user->role = $data['role']; // validated against SELF_SERVICE_ROLES; never mass-assigned
        $user->save();

        return $this->issue($request, $user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            // Same message for unknown email and wrong password — no account enumeration.
            throw ValidationException::withMessages(['email' => 'Those details do not match an account.']);
        }

        return $this->issue($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete(); // mobile: revoke this device's token
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function issue(Request $request, User $user, int $status = 200): JsonResponse
    {
        $body = ['data' => (new UserResource($user))->resolve($request)];

        if ($request->filled('device_name')) {
            $body['token'] = $user->createToken($request->string('device_name')->limit(100)->toString())->plainTextToken;
        } else {
            Auth::guard('web')->login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        return response()->json($body, $status);
    }
}
