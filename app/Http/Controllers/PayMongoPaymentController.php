<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\SadminSetting;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\SubscriptionRepository;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PayMongoPaymentController extends AppBaseController
{
    const API_BASE_URL = 'https://api.paymongo.com/v1';

    /** @var SubscriptionRepository */
    private $subscriptionRepository;

    public function __construct(SubscriptionRepository $subscriptionRepository)
    {
        $this->subscriptionRepository = $subscriptionRepository;
    }

    private function getSecretKey()
    {
        $sadminSetting = SadminSetting::whereIn('key', ['paymongo_key', 'paymongo_secret'])->pluck('value', 'key')->toArray();

        return !empty($sadminSetting['paymongo_secret']) ? $sadminSetting['paymongo_secret'] : config('services.paymongo.secret');
    }

    public function generateSession(Request $request)
    {
        $input = $request->validate([
            'plan_id' => 'required',
            'amount' => 'required',
        ]);

        $plan = Plan::findorfail($input['plan_id']);

        // PayMongo only settles in Philippine Peso.
        if (strtoupper($plan->currency->code) !== 'PHP') {
            return $this->sendError(__('messages.error.currency_not_supported_paymongo'));
        }

        $amount = $input['amount'] * 100;

        $secretKey = $this->getSecretKey();

        if (empty($secretKey)) {
            return $this->sendError('PayMongo Secret is not set');
        }

        $reference = 'paymongo_' . bin2hex(random_bytes(6));

        $metadata = [
            'user_id' => Auth::id(),
            'plan_id' => $plan->id,
            'amount' => $amount,
            'stores' => json_encode($request->stores ?? []),
        ];

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->post(self::API_BASE_URL . '/checkout_sessions', [
                    'data' => [
                        'attributes' => [
                            'billing' => [
                                'name' => Auth::user()->full_name,
                                'email' => Auth::user()->email,
                            ],
                            'send_email_receipt' => false,
                            'show_description' => true,
                            'show_line_items' => true,
                            'line_items' => [[
                                'amount' => (int) $amount,
                                'currency' => 'PHP',
                                'name' => $plan->name,
                                'description' => $plan->description,
                                'quantity' => 1,
                            ]],
                            'payment_method_types' => ['qrph'],
                            'reference_number' => $reference,
                            'metadata' => $metadata,
                            'success_url' => route('paymongo-success') . '?reference_number=' . $reference,
                            'cancel_url' => route('paymongo-failed', ['plan_id' => $plan->id, 'amount' => $input['amount']]) . '&reference_number=' . $reference,
                        ],
                    ],
                ]);

            if ($response->failed()) {
                return $this->sendError($response->json('errors.0.detail') ?? 'Unable to create PayMongo checkout session.');
            }

            $session = $response->json('data');

            // Remember which PayMongo session belongs to our reference so the
            // return leg can re-fetch it from PayMongo and verify it was paid.
            Cache::put('paymongo_session_' . $reference, $session['id'], now()->addMinutes(30));

            return $this->sendResponse(['url' => $session['attributes']['checkout_url']], 'PayMongo session generated successfully.');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function paymentSuccess(Request $request)
    {
        $reference = $request->query('reference_number');

        if (empty($reference)) {
            return redirect('/app/user/payment-failed?status=false');
        }

        $sessionId = Cache::get('paymongo_session_' . $reference);

        if (empty($sessionId)) {
            return redirect('/app/user/payment-failed?status=false');
        }

        $secretKey = $this->getSecretKey();

        try {
            DB::beginTransaction();

            $response = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->get(self::API_BASE_URL . '/checkout_sessions/' . $sessionId);

            if ($response->failed()) {
                DB::rollBack();
                return redirect('/app/user/payment-failed?status=false');
            }

            $session = $response->json('data');
            $paymentIntentStatus = $session['attributes']['payment_intent']['attributes']['status'] ?? null;

            if ($paymentIntentStatus !== 'succeeded') {
                DB::rollBack();
                return redirect('/app/user/payment-failed?status=false');
            }

            $metaData = $session['attributes']['metadata'] ?? [];
            $user = User::findorfail($metaData['user_id']);

            $transaction = Transaction::create([
                'tenant_id' => $user->tenant_id,
                'transaction_id' => $session['id'],
                'amount' => $metaData['amount'] / 100,
                'type' => Subscription::TYPE_PAYMONGO,
                'status' => Transaction::PAID,
                'user_id' => $user->id,
                'meta' => json_encode($session),
            ]);

            $stores = $metaData['stores'] ?? null;

            if (!empty($stores)) {
                if (is_string($stores)) {
                    $decoded = json_decode($stores, true);
                    $stores = is_array($decoded) ? $decoded : null;
                }
                if (is_array($stores)) {
                    $stores = array_filter($stores, fn($item) => !empty($item));
                    $stores = empty($stores) ? null : array_values($stores);
                }
            }

            $this->subscriptionRepository->createSubscription([
                'plan_id' => $metaData['plan_id'],
                'user_id' => $metaData['user_id'],
                'stores' => $stores ?? null,
                'payment_type' => Subscription::TYPE_PAYMONGO,
                'transaction_id' => $transaction->id,
            ]);

            Cache::forget('paymongo_session_' . $reference);

            DB::commit();

            return redirect('/app/user/payment-success?status=true');
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function paymentFailed(Request $request)
    {
        $reference = $request->query('reference_number');

        if (!empty($reference)) {
            Cache::forget('paymongo_session_' . $reference);
        }

        return redirect('/app/user/payment-failed?status=false');
    }
}
