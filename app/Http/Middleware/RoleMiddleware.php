<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (!$request->user()) {
            return response()->json([
                'error' => 'Unauthenticated. Please login first.'
            ], 401);
        }

        if ($request->user()->role !== $role) {
            return response()->json([
                'error' => 'Unauthorized. You do not have access to this.'
            ], 403);
        }

        return $next($request);
    }
}