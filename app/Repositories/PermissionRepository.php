<?php

namespace App\Repositories;

use App\Models\Permission;
use Illuminate\Support\Str;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;

/**
 * Class PermissionRepository
 */
class PermissionRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name',
        'display_name',
    ];

    /**
     * @var string[]
     */
    protected $allowedFields = [
        'name',
        'description',
    ];

    /**
     * Return searchable fields
     */
    public function getFieldsSearchable(): array
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     **/
    public function model(): string
    {
        return Permission::class;
    }

    public function getPermission($perPage)
    {
        $user = Auth::user();
        $currentSubscription = Subscription::where('user_id', $user->id)->where('status', Subscription::ACTIVE)->first();
        $restrictedPermissions = [];
        if (!empty($currentSubscription) && isset($currentSubscription->plan->planFeature)) {
            $restrictedPermissions = $currentSubscription->plan->planFeature->restrictedPermissions();
        }

        $managePermissions = $this->model
            ->where('name', 'like', 'manage_%')
            ->whereNotIn('name', $restrictedPermissions)
            ->get();

        $allPermissions = Permission::all(['id', 'name']);

        $managePermissions->each(function ($permission) use ($allPermissions) {
            $module = Str::after($permission->name, 'manage_');

            $childPermissions = collect(['edit', 'create', 'view', 'delete'])->map(function ($action) use ($module, $allPermissions) {
                $name = "{$action}_{$module}";
                $match = $allPermissions->firstWhere('name', $name);

                if ($match) {
                    return [
                        'id' => $match->id,
                        'name' => $name,
                        'selected' => false,
                    ];
                }
                return null;
            })->filter()->values();

            $permission->child_permissions = $childPermissions;
        });

        return $managePermissions;

    }
}
