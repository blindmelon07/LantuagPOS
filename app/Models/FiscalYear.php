<?php

namespace App\Models;

use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use App\Traits\Multitenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class FiscalYear extends BaseModel implements JsonResourceful
{
    use HasFactory, HasJsonResourcefulData, BelongsToTenant, Multitenantable;

    protected $table = 'fiscal_years';

    public const JSON_API_TYPE = 'fiscal_years';

    protected $fillable = [
        'tenant_id',
        'name',
        'start_date',
        'end_date',
        'is_active',
        'is_completed',
    ];

    public $casts = [
        'name' => 'string',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'is_completed' => 'boolean',
    ];

    public function prepareLinks(): array
    {
        return [];
    }

    public function prepareAttributes(): array
    {
        return [
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_active' => $this->is_active,
            'is_completed' => $this->is_completed,
        ];
    }
}
