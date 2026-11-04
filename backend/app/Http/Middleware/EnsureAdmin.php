<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error(
                'UNAUTHENTICATED',
                ApiResponse::message('UNAUTHENTICATED', Locale::fromRequest($request)),
                401
            );
        }
        if (($user->role ?? null) !== 'admin') {
            return ApiResponse::error(
                'FORBIDDEN',
                ApiResponse::message('FORBIDDEN', Locale::fromRequest($request)),
                403
            );
        }

        return $next($request);
    }
}
