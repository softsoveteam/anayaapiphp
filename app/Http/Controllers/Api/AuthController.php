<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unique_id' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('unique_id', $data['unique_id'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'unique_id' => ['Invalid unique ID or password.'],
            ]);
        }

        if (! $user->canLogin()) {
            throw ValidationException::withMessages([
                'unique_id' => ['Account is not active yet. Login is allowed after onboarding.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('anaya')->plainTextToken;
        $user->load(['activeComputerAssignments.computer', 'roles']);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $request->user()->load(['activeComputerAssignments.computer', 'roles']);

        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'unique_id' => ['sometimes', 'string', 'max:50', 'unique:users,unique_id,'.$user->id],
            'name' => ['sometimes', 'string', 'max:255'],
            'current_password' => ['required_with:unique_id', 'string'],
        ]);

        if (isset($data['current_password']) && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(collect($data)->only(['unique_id', 'name'])->all());
        $user->load(['activeComputerAssignments.computer', 'roles']);

        return response()->json([
            'message' => 'Profile updated.',
            'user' => new UserResource($user),
        ]);
    }
}
