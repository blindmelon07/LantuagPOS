<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\PlanCollection;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionCollection;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Models\SadminSetting;
use App\Models\Subscription;
use App\Repositories\SubscriptionRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Class SubscriptionAPIController
 */
class SubscriptionAPIController extends AppBaseController
{
    /** @var SubscriptionRepository */
    private $subscriptionRepository;

    public function __construct(SubscriptionRepository $subscriptionRepository)
    {
        $this->subscriptionRepository = $subscriptionRepository;
    }

    public function index(Request $request): SubscriptionCollection
    {
        $perPage = getPageSize($request);
        $filter = $request->get('filter') ?? null;
        $subscriptions = $this->subscriptionRepository->getUserSubscriptions($perPage, $filter);
        SubscriptionResource::usingWithCollection();

        return new SubscriptionCollection($subscriptions);
    }

    public function getPlans(Request $request)
    {
        $perPage = getPageSize($request);
        $plans = Plan::whereNot('assign_while_register', 1)->paginate($perPage);
        PlanResource::usingWithCollection();

        return new PlanCollection($plans);
    }

    public function getCurrentPlan()
    {
        $currentSubscription = Subscription::activeFor(Auth::id());
        if (!empty($currentSubscription)) {
            $currentSubscription['currency_symbol'] = $currentSubscription->plan->currency->symbol ?? '';
            foreach ($currentSubscription->usageSummary() as $key => $value) {
                $currentSubscription[$key] = $value;
            }

            return $this->sendResponse($currentSubscription->toArray(), 'Current Plan retrieved successfully');
        }

        return $this->sendError('Current Plan not found');
    }

    public function getComparePlans(Plan $plan)
    {
        $plan['currency_symbol'] = $plan->currency->symbol ?? '';
        $start_date = Carbon::now();
        // Purchases from this screen are paid, so trial days only apply to free plans.
        $end_date = $plan->calculateEndDate($start_date, $plan->shouldApplyTrial());
        $plan['start_date'] = $start_date->format('jS F, Y');
        $plan['end_date'] = $end_date->format('jS F, Y');
        $plan['total_days'] = $start_date->copy()->startOfDay()->diffInDays($end_date->copy()->startOfDay());

        $currentSubscription = Subscription::activeFor(Auth::id());
        if (!empty($currentSubscription)) {
            foreach ($currentSubscription->usageSummary() as $key => $value) {
                $currentSubscription[$key] = $value;
            }
            $currentSubscription['currency_symbol'] = $currentSubscription->plan->currency->symbol ?? '';
            $currentSubscription['start_date'] = Carbon::parse($currentSubscription->start_date)->format('jS F, Y');
            $currentSubscription['end_date'] = Carbon::parse($currentSubscription->end_date)->format('jS F, Y');
        }
        $plan['payable_amount'] = SubscriptionRepository::calculatePayableAmount($plan, Auth::id());

        $data = [
            'current_plan' => $currentSubscription,
            'new_plan' => $plan,
        ];
        return $this->sendResponse($data, 'Current Plan retrieved successfully');
    }

    public function getPaymentMethods()
    {
        $keyName = [
            'manual_payment_enabled',
            'stripe_enabled',
            'paypal_enabled',
            'razorpay_enabled',
            'paystack_enabled',
            'paymongo_enabled',
        ];

        $paymentTypeKeys = [
            'manual_payment_enabled' => Subscription::TYPE_MANUAL,
            'stripe_enabled' => Subscription::TYPE_STRIPE,
            'paypal_enabled' => Subscription::TYPE_PAYPAL,
            'razorpay_enabled' => Subscription::TYPE_RAZORPAY,
            'paystack_enabled' => Subscription::TYPE_PAYSTACK,
            'paymongo_enabled' => Subscription::TYPE_PAYMONGO,
        ];

        $settings = SadminSetting::whereIn('key', $keyName)
            ->where('value', 1)
            ->pluck('key')
            ->map(function ($key) use ($paymentTypeKeys) {
                return $paymentTypeKeys[$key] ?? null;
            })
            ->filter()
            ->map(function ($type) {
                return [
                    'type' => $type,
                    'name' => Subscription::PAYMENT_TYPES[$type]
                ];
            })
            ->values();
        $data['payment_methods'] = $settings;
        $data['manual_payment_guide'] = getSadminSettingValue('manual_payment_guide') ?? null;

        return $this->sendResponse($data, 'Payment Methods retrieved successfully');
    }

    public function createSubscription(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'payment_type' => 'required',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        if ((int) $validated['payment_type'] == Subscription::TYPE_MANUAL) {
            $input = $request->all();
            // The manual-payment form sends the stores to keep as a JSON "branches" field.
            if (empty($input['stores']) && !empty($input['branches'])) {
                $branches = is_string($input['branches']) ? json_decode($input['branches'], true) : $input['branches'];
                $input['stores'] = is_array($branches) ? array_values(array_filter($branches)) : null;
            }
            unset($input['user_id'], $input['status']);
            $this->subscriptionRepository->createSubscription($input);
            return $this->sendSuccess(__('messages.success.subscription_created'));
        }

        return $this->sendError('Selected payment type is not available');
    }
}
