<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $lang = Locale::fromRequest($request);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::error('UNAUTHENTICATED', $lang === 'en' ? 'Email or password is incorrect.' : 'Email atau kata sandi salah.', 401);
        }
        if ($user->role !== 'admin') {
            return ApiResponse::error('FORBIDDEN', ApiResponse::message('FORBIDDEN', $lang), 403);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('admin')->plainTextToken;

        return ApiResponse::ok([
            'token' => $token,
            'user' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::ok(['ok' => true]);
    }
}
