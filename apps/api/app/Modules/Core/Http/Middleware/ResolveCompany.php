<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Support\CurrentCompany;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-011: resolve company aktif untuk request.
 * Sumber: header X-Company-Id (harus termasuk company yang boleh diakses user).
 */
class ResolveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $requested = $request->header('X-Company-Id');
        $allowed = $user->companies()->pluck('companies.id')->all();
        $allowed[] = $user->company_id;
        $allowed = array_values(array_unique(array_map('intval', array_filter($allowed))));

        $companyId = $requested !== null ? (int) $requested : (int) $user->company_id;

        if ($companyId <= 0 || ! in_array($companyId, $allowed, true)) {
            abort(403, 'Anda tidak memiliki akses ke company ini.');
        }

        CurrentCompany::set($companyId);

        try {
            return $next($request);
        } finally {
            // Critical for long-lived workers (Octane) and exception paths: a
            // tenant context must never leak into the next request.
            CurrentCompany::clear();
        }
    }
}
