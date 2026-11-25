<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\AuthEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request, AuthEventLogger $journal)
    {
        if (!config('app.allow_registration', true)) {
            return response()->json(['message' => 'Registration is disabled'], 403);
        }

        $validated = $request->validate([
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
        ]);

        $user = User::create([
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'status' => 'active',
        ]);

        Cache::forget('users.all');

        $token = $user->createToken('auth_token')->plainTextToken;

        $journal->registered($request, $user);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request, AuthEventLogger $journal)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Always authenticate against the session guard: Sanctum's request
        // guard may have become the default earlier in the same process.
        if (! Auth::guard('web')->attempt($validated)) {
            $journal->failed($request, $validated['email']);

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = User::where('email', $validated['email'])->first();
        $user->load('role');

        if ($user->status !== 'active') {
            $journal->failed($request, $validated['email'], 'account_inactive');

            return response()->json(['message' => 'Account is not active'], 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => TwoFactorController::challengeToken($user),
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $journal->login($request, $user);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request, AuthEventLogger $journal)
    {
        $user = $request->user();

        $request->user()->currentAccessToken()->delete();

        $journal->logout($request, $user);

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Active API sessions (Sanctum tokens) of the current user.
     */
    public function sessions(Request $request)
    {
        /** @var PersonalAccessToken|null $currentToken */
        $currentToken = $request->user()->currentAccessToken();
        $currentId = $currentToken !== null ? (int) $currentToken->id : null;

        $sessions = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->map(fn ($token) => [
                'id' => (int) $token->id,
                'name' => $token->name,
                'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                'created_at' => optional($token->created_at)->toIso8601String(),
                'expires_at' => optional($token->expires_at)->toIso8601String(),
                'current' => (int) $token->id === (int) $currentId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, int $id)
    {
        $request->user()->tokens()->whereKey($id)->firstOrFail()->delete();

        return response()->json(['message' => 'Сессия завершена']);
    }

    public function revokeOtherSessions(Request $request)
    {
        /** @var PersonalAccessToken|null $currentToken */
        $currentToken = $request->user()->currentAccessToken();
        $currentId = $currentToken !== null ? (int) $currentToken->id : null;

        $request->user()->tokens()
            ->when($currentId !== null, fn ($query) => $query->whereKeyNot($currentId))
            ->delete();

        return response()->json(['message' => 'Остальные сессии завершены']);
    }

    public function refresh(Request $request, AuthEventLogger $journal)
    {
        $user = $request->user();
        $token = $user->createToken('auth_token')->plainTextToken;

        $journal->tokenRefreshed($request, $user);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('role'));
    }

    public function allUsers()
    {
        // Short-lived cache: the chat user picker calls this often.
        $users = Cache::remember('users.all', 60, function () {
            return User::where('status', 'active')
                ->with('role')
                ->orderByRaw("LOWER(COALESCE(first_name, '')) ASC")
                ->orderByRaw("LOWER(COALESCE(last_name, '')) ASC")
                ->orderByRaw("LOWER(COALESCE(email, '')) ASC")
                ->get();
        });

        return response()->json($users);
    }

    public function updateProfile(Request $request, AuthEventLogger $journal)
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $request->user()->id,
            'old_password' => 'nullable|string',
            'password' => 'nullable|min:8|confirmed',
        ]);

        $user = $request->user();
        $passwordChanged = false;

        // Handle password change
        if (isset($validated['password'])) {
            if (!isset($validated['old_password'])) {
                return response()->json(['message' => 'Current password is required'], 422);
            }

            if (!Hash::check($validated['old_password'], $user->password)) {
                return response()->json(['message' => 'Current password is incorrect'], 422);
            }

            $user->password = Hash::make($validated['password']);
            $passwordChanged = true;
            unset($validated['old_password']);
            unset($validated['password']);
            unset($validated['password_confirmation']);
        }

        $user->update($validated);

        if ($passwordChanged) {
            $journal->passwordChanged($request, $user, 'profile');
        }

        Cache::forget('users.all');

        return response()->json($user->load('role'));
    }

    public function uploadAvatar(Request $request)
    {
        $validated = $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $file = $request->file('avatar');
        $path = $file->store('avatars', 'public');

        $request->user()->update(['avatar' => $path]);

        Cache::forget('users.all');

        return response()->json(['avatar' => $path]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users',
        ]);

        $status = Password::sendResetLink($validated);

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Password reset link sent'])
            : response()->json(['message' => 'Failed to send reset link'], 500);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $validated,
            function (User $user) use ($validated) {
                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                    'remember_token' => Str::random(60),
                ])->save();
            },
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password reset successfully'])
            : response()->json(['message' => 'Failed to reset password'], 500);
    }

    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification hash'], 403);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['message' => 'Email verified successfully']);
    }
}
