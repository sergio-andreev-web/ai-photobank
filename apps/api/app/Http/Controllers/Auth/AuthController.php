<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Support\Presenters\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Session-cookie authentication (ТЗ §13, §50; ADR-0001). Implemented with
 * Laravel's built-in `web` guard/session/CSRF stack rather than the Sanctum
 * package: same-origin deployment means no cross-site token exchange is
 * needed, and the contract's behavior (cookie session, X-XSRF-TOKEN header,
 * `/sanctum/csrf-cookie` route) is exactly what Laravel's core CSRF
 * middleware already provides once a session is started.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::guard('web')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ])) {
            throw new ApiException('INVALID_CREDENTIALS', 422, 'Invalid email or password');
        }

        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = Auth::guard('web')->user();

        if ($user->role !== 'admin') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new ApiException('FORBIDDEN', 403, 'Only administrators may sign in.');
        }

        return response()->json(UserPresenter::toArray($user));
    }

    public function logout(Request $request): Response
    {
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(UserPresenter::toArray($request->user()));
    }
}
