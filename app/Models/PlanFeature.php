<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    use HasFactory;

    protected $table = 'plan_features';

    protected $fillable = [
        'plan_id',
        'pos_management',
        'reports',
        'emails_support',
        'sms_support',
        'inventory_management',
        'adjustments',
        'roles_permission',
        'kpi',
        'accounting',
    ];

    /** Plan feature => permissions that are hidden when the plan doesn't include it. */
    const FEATURE_PERMISSIONS = [
        'pos_management' => ['manage_pos_screen'],
        'reports' => ['manage_reports'],
        'emails_support' => ['manage_email_templates'],
        'sms_support' => ['manage_sms_templates', 'manage_sms_apis'],
        'inventory_management' => [],
        'adjustments' => ['manage_adjustments'],
        'roles_permission' => ['manage_roles'],
        'kpi' => ['manage_kpi'],
        'accounting' => ['manage_accounting'],
    ];

    public function restrictedPermissions(): array
    {
        $restricted = [];
        foreach (self::FEATURE_PERMISSIONS as $feature => $permissions) {
            if (! $this->{$feature}) {
                $restricted = array_merge($restricted, $permissions);
            }
        }

        return $restricted;
    }

    /**
     * Whether the subscription owned by $userId includes $feature.
     * A plan without a feature row has no restrictions (existing behaviour).
     */
    public static function userHasFeature($userId, string $feature): bool
    {
        $subscription = Subscription::activeFor($userId);
        $planFeature = $subscription?->plan?->planFeature;

        return empty($planFeature) || (bool) $planFeature->{$feature};
    }
}
