<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-110: permission dicek SERVER-SIDE di setiap endpoint.
 * Pemakaian: ->middleware('permission:sales.order.create')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if (! $user->hasPermission($permission)) {
            abort(403, "Permission [{$permission}] diperlukan.");
        }

        return $next($request);
    }
}
