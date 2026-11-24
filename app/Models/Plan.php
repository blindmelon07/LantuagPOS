<?php

namespace App\Models;

use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Plan extends BaseModel implements JsonResourceful
{
    use HasFactory, HasJsonResourcefulData;

    const JSON_API_TYPE = 'plans';

    protected $fillable = [
        'name',
        'price',
        'frequency',
        'assign_while_register',
        'trial_days',
        'currency_id',
        'no_of_stores',
    ];

    public static $rules = [
        'name' => 'required',
        'currency_id' => 'required',
        'price' => 'required',
        'frequency' => 'required|in:1,2,3,4',
        'no_of_stores' => 'required|numeric|min:1',
        'assign_while_register' => 'boolean',
        'trial_days' => 'nullable|integer|min:0|max:'.self::MAX_TRIAL_DAYS,
    ];

    const MAX_TRIAL_DAYS = 3650;

    const WEEKLY = 1;

    const MONTHLY = 2;

    const YEARLY = 3;

    const UNLIMITED = 4;

    const FREQUENCY = [
        self::WEEKLY => 'Weekly',
        self::MONTHLY => 'Monthly',
        self::YEARLY => 'Yearly',
        self::UNLIMITED => 'Unlimited',
    ];

    public function prepareLinks(): array
    {
        return [
            'self' => route('plans.show', $this->id),
        ];
    }

    public function prepareAttributes(): array
    {
        return [
            'name' => $this->name,
            'price' => $this->price,
            'frequency' => $this->frequency,
            'assign_while_register' => $this->assign_while_register,
            'trial_days' => $this->trial_days,
            'currency_id' => $this->currency_id,
            'no_of_stores' => $this->no_of_stores,
            'currency_symbol' => $this->currency->symbol,
            'features' => $this->planFeature,
            'created_at' => $this->created_at,
        ];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function planFeature(): HasOne
    {
        return $this->hasOne(PlanFeature::class, 'plan_id');
    }

    /**
     * Trial days only apply to free subscriptions (registration / zero-priced plans).
     * A paid purchase always gets the plan's full billing period.
     */
    public function shouldApplyTrial(bool $isFree = false): bool
    {
        return $this->trial_days > 0 && ($isFree || $this->price <= 0);
    }

    public function calculateEndDate(?Carbon $startDate = null, bool $applyTrial = false): Carbon
    {
        $start = $startDate ? $startDate->copy() : Carbon::now();

        if ($applyTrial && $this->trial_days > 0) {
            return $start->addDays(min((int) $this->trial_days, self::MAX_TRIAL_DAYS))->endOfDay();
        }

        return match ((int) $this->frequency) {
            self::MONTHLY => $start->addMonthNoOverflow()->endOfDay(),
            self::YEARLY, self::UNLIMITED => $start->addYear()->endOfDay(),
            default => $start->addDays(7)->endOfDay(),
        };
    }
}
