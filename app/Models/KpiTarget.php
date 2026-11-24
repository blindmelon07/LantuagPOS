<?php

namespace App\Models;

use App\Traits\Multitenantable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Monthly sales target for a whole store (user_id = null) or a single cashier.
 */
class KpiTarget extends Model
{
    use BelongsToTenant, Multitenantable;

    protected $table = 'kpi_targets';

    protected $fillable = [
        'tenant_id',
        'period',
        'user_id',
        'sales_target',
        'transactions_target',
    ];

    protected $casts = [
        'sales_target' => 'float',
        'transactions_target' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }
}
