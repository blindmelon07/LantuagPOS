<?php

namespace App\Models;

use App\Traits\Multitenantable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A payment made to a supplier against a purchase (accounts payable).
 */
class PurchasePayment extends Model
{
    use BelongsToTenant, Multitenantable;

    protected $table = 'purchase_payments';

    protected $fillable = [
        'tenant_id',
        'purchase_id',
        'payment_date',
        'amount',
        'payment_type',
        'reference',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'amount' => 'float',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }
}
