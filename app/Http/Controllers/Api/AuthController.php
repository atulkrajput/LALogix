<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Admin login/logout. Replaces the old JWT cookie (dp_admin_token) with a
 * standard Laravel session.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (! $email || ! $password) {
            return response()->json(['error' => 'Email and password are required'], 400);
        }

        $user = User::where('email', $email)->first();

        // Passwords hashed by the old app use bcryptjs ($2a$/$2b$), which
        // password_verify() accepts; they are rehashed to Laravel's format on login.
        if (! $user || ! password_verify($password, $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (! str_starts_with($user->password, '$2y$') || Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['success' => true, 'user' => $this->present($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        return response()->json(['user' => $this->present($user)]);
    }

    private function present(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role];
    }
}
