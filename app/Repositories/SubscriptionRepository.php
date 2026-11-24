<?php

namespace App\Repositories;

use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class SubscriptionRepository
 */
class SubscriptionRepository extends BaseRepository
{
    /** Gateways whose callback only fires after a confirmed payment, so the subscription starts active. */
    const ONLINE_PAYMENT_TYPES = [
        Subscription::TYPE_STRIPE,
        Subscription::TYPE_PAYPAL,
        Subscription::TYPE_RAZORPAY,
        Subscription::TYPE_PAYSTACK,
        Subscription::TYPE_PAYMONGO,
    ];

    protected $fieldSearchable = [
        'transaction_id',
        'plan_amount',
        'payable_amount',
        'plan_frequency',
        'status',
    ];

    /**
     * {@inheritDoc}
     */
    public function getFieldsSearchable()
    {
        return $this->fieldSearchable;
    }

    /**
     * {@inheritDoc}
     */
    public function model()
    {
        return Subscription::class;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getActiveSubscriptions($perPage, $filter = null)
    {
        $search = $filter['search'] ?? null;

        $query = Subscription::withoutGlobalScopes()
            ->with(['plan', 'user', 'plan.currency'])
            ->whereNotIn('status', [Subscription::PENDING, Subscription::REJECTED]);

        if (isset($filter['status'])) {
            if ($filter['status'] == Subscription::ACTIVE) {
                $query->where('status', Subscription::ACTIVE);
            } else {
                $query->where('status', Subscription::INACTIVE);
            }
        }

        if (isset($filter['plan_type']) && array_key_exists($filter['plan_type'], Plan::FREQUENCY)) {
            $query->where('plan_frequency', $filter['plan_type']);
        }

        if (isset($filter['payment_type']) && array_key_exists($filter['payment_type'], Subscription::PAYMENT_TYPES)) {
            $query->where('payment_type', $filter['payment_type']);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($q2) use ($search) {
                    $q2->withoutGlobalScopes()
                        ->where(function ($subQuery) use ($search) {
                            $subQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                })
                    ->orWhereHas('plan', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('plan_amount', 'like', "%{$search}%")
                    ->orWhere('payable_amount', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getManualSubscriptions($perPage, $filter = null, $status = null)
    {
        $search = $filter['search'] ?? null;

        $query = Subscription::withoutGlobalScopes()
            ->with(['plan', 'user'])
            ->where('payment_type', Subscription::TYPE_MANUAL);

        if (isset($status)) {
            $query->where('status', $status);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($q2) use ($search) {
                    $q2->withoutGlobalScopes()
                        ->where(function ($subQuery) use ($search) {
                            $subQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                })
                    ->orWhereHas('plan', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('plan_amount', 'like', "%{$search}%")
                    ->orWhere('payable_amount', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getUserSubscriptions($perPage, $filter = null)
    {
        $search = $filter['search'] ?? null;

        $query = Subscription::with('plan')->where('user_id', Auth::id());

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($q2) use ($search) {
                    $q2->whereRaw(
                        "CONCAT(first_name, ' ', last_name) LIKE ?",
                        ["%{$search}%"]
                    );
                })
                    ->orWhereHas('plan', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('plan_amount', 'like', "%{$search}%")
                    ->orWhere('payable_amount', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Server-side amount a user must pay to switch to $plan: plan price minus the unused
     * balance of their current, non-expired subscription. Never trust the client for this.
     */
    public static function calculatePayableAmount(Plan $plan, $userId): float
    {
        $payable = (float) $plan->price;
        $currentSubscription = Subscription::activeFor($userId);
        if (!empty($currentSubscription) && !$currentSubscription->isExpired()) {
            $payable -= $currentSubscription->usageSummary()['remaining_balance'];
        }

        return max(0, round($payable, 2));
    }

    public static function createSubscription($data)
    {
        try {
            DB::beginTransaction();
            $plan = Plan::find($data['plan_id']);
            $user_id = $data['user_id'] ?? Auth::id();
            $notes = $data['notes'] ?? null;
            $attachment = $data['attachment'] ?? null;
            $paymentType = $data['payment_type'] ?? null;
            $transactionId = $data['transaction_id'] ?? null;
            $storeIds = $data['stores'] ?? null;
            $applyTrial = $plan->shouldApplyTrial($paymentType !== null && (int) $paymentType === Subscription::TYPE_FREE);
            $end_date = $plan->calculateEndDate(null, $applyTrial);

            if ($paymentType == Subscription::TYPE_MANUAL && $storeIds) {
                $storeData = json_encode([
                    'active_store' => User::find($user_id)->tenant_id,
                    'stores' => $storeIds,
                ]);
            }
            $subscriptionData = [
                'user_id' => $user_id,
                'plan_id' => $plan->id,
                'transaction_id' => $transactionId,
                'payment_type' => $paymentType,
                'plan_amount' => $plan->price,
                'payable_amount' => $plan->price,
                'plan_frequency' => $plan->frequency,
                'start_date' => Carbon::now(),
                'end_date' => $end_date,
                'trial_ends_at' => $applyTrial ? $end_date : null,
                'status' => $data['status'] ?? Subscription::PENDING,
                'notes' => $notes,
                'data' => $storeData ?? null,
            ];


            if ($paymentType !== null) {
                if (in_array((int) $paymentType, self::ONLINE_PAYMENT_TYPES, true)) {
                    $subscriptionData['status'] = Subscription::ACTIVE;
                    $subscriptionData['is_paid'] = 1;
                } elseif ($paymentType == Subscription::TYPE_FREE) {
                    $subscriptionData['payable_amount'] = 0;
                    $subscriptionData['status'] = Subscription::ACTIVE;
                }
            }

            // Credit the unused part of the user's current (non-expired) subscription.
            $currentSubscription = Subscription::activeFor($user_id);
            if (!empty($currentSubscription) && !$currentSubscription->isExpired()) {
                $remainingBalance = $currentSubscription->usageSummary()['remaining_balance'];

                $price = round($subscriptionData['payable_amount'] - $remainingBalance, 2);
                if ($price <= 0) {
                    $subscriptionData['payment_type'] = $currentSubscription['payment_type'];
                    $subscriptionData['payable_amount'] = 0;
                    $subscriptionData['status'] = Subscription::ACTIVE;
                } else {
                    $subscriptionData['payable_amount'] = $price;
                }
            }

            // Nothing to pay (free plan): no approval is needed.
            if ($plan->price <= 0) {
                $subscriptionData['payable_amount'] = 0;
                $subscriptionData['status'] = Subscription::ACTIVE;
            }

            $subscription = Subscription::create($subscriptionData);

            // Inactive old subscription
            if ($subscription->status == Subscription::ACTIVE) {
                Subscription::where('user_id', $subscription->user_id)
                    ->whereNot('id', $subscription->id)
                    ->where('status', Subscription::ACTIVE)
                    ->update(['status' => Subscription::INACTIVE]);
                if ($storeIds) {
                    $userAllStores = Store::where('user_id', $subscription->user_id)->get();
                    foreach ($userAllStores as $store) {
                        if (!in_array($store->id, $storeIds)) {
                            $store->update(['status' => 0]);
                        }
                    }
                    $disabledStores = Store::where('user_id', $subscription->user_id)->where('status', 0)->get();
                    foreach ($disabledStores as $store) {
                        UserStore::where('store_id', $store->id)->delete();
                        $disabledStoresUsers = User::where('tenant_id', $store->tenant_id)->get();
                        foreach ($disabledStoresUsers as $disabledStoreUser) {
                            $firstActiveStore = UserStore::where('user_id', $disabledStoreUser->id)->first();
                            if ($firstActiveStore && $firstActiveStore->store->tenant_id) {
                                $disabledStoreUser->update(['tenant_id' => $firstActiveStore->store->tenant_id]);
                            }
                        }
                    }
                }
            }

            // Add Attachment
            if ($attachment != null && !empty($attachment)) {
                $subscription->addMedia($attachment)->toMediaCollection(Subscription::ATTACHMENT);
            }

            DB::commit();

            return $subscription;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
