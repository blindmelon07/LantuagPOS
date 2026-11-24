<?php

namespace App\Http\Middleware;

use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route unless the store owner's plan includes the given feature.
 * Usage: ->middleware('plan_feature:kpi')
 */
class CheckPlanFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = Auth::user();

        if (! $user || $user->hasRole(Role::SUPER_ADMIN)) {
            return $next($request);
        }

        $ownerId = $user->id;
        if (! ($user->hasRole(Role::ADMIN) && $user->subscriptions->count() > 0)) {
            $ownerId = Store::where('tenant_id', $user->tenant_id)->value('user_id') ?? $user->id;
        }

        if (! PlanFeature::userHasFeature($ownerId, $feature)) {
            return response()->json([
                'success' => false,
                'message' => 'Your current plan does not include this feature. Please upgrade your plan.',
            ], 403);
        }

        return $next($request);
    }
}
