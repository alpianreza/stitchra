<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;

/**
 * BR-111: auth security — hashing modern, lockout, rate limit, dan token Sanctum.
 * Endpoint ini menerbitkan API/device token; browser SPA dapat memakai stateful cookie.
 */
class AuthController extends Controller
{
    private const MAX_FAILED = 5;
    private const LOCKOUT_MINUTES = 15;
    private const TOKEN_ABILITY = 'api:access';

    public function __construct(private AuditService $audit) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $emailKey = 'login:email:'.strtolower($credentials['email']).'|'.$request->ip();
        $ipKey = 'login:ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($emailKey, 10) || RateLimiter::tooManyAttempts($ipKey, 50)) {
            abort(429, 'Terlalu banyak percobaan login. Coba lagi nanti.');
        }

        $user = User::withoutGlobalScopes()->where('email', $credentials['email'])->first();

        if ($user && $user->isLockedOut()) {
            abort(423, 'Akun terkunci sampai '.$user->locked_until->toDateTimeString());
        }

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($emailKey, 300);
            RateLimiter::hit($ipKey, 300);

            if ($user) {
                $user->failed_logins++;
                if ($user->failed_logins >= self::MAX_FAILED) {
                    $user->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
                    $user->failed_logins = 0;
                }
                $user->save();
            }

            throw ValidationException::withMessages(['email' => ['Kredensial tidak valid.']]);
        }

        RateLimiter::clear($emailKey);
        RateLimiter::clear($ipKey);
        $user->fill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        $ttlMinutes = max(1, (int) config('sanctum.expiration', 480));
        $expiresAt = now()->addMinutes($ttlMinutes);
        $tokenName = $credentials['device_name'] ?? 'api-client';
        $newToken = $user->createToken($tokenName, [self::TOKEN_ABILITY], $expiresAt);

        $this->audit->record('login', $user, after: [
            'token_name' => $tokenName,
            'expires_at' => $expiresAt->toIso8601String(),
        ], request: $request);

        return response()->json([
            'token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->payload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        $this->audit->record('logout', $request->user(), request: $request);

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->payload($request->user())]);
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'company_id' => $user->company_id,
            'roles' => $user->roles()->pluck('code'),
            'permissions' => $user->roles()->with('permissions')->get()
                ->flatMap(fn ($role) => $role->permissions->pluck('code'))
                ->unique()->values(),
            'companies' => $user->companies()->pluck('companies.id'),
        ];
    }
}
