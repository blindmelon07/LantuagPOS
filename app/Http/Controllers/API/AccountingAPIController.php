<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountingAPIController extends AppBaseController
{
    private function finance(Request $request): FinanceService
    {
        return new FinanceService($request->integer('warehouse_id') ?: null);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        [$start, $end] = FinanceService::range($request->get('start_date'), $request->get('end_date'));

        return $this->sendResponse($this->finance($request)->profitAndLoss($start, $end), 'Profit and loss retrieved successfully');
    }

    public function vat(Request $request): JsonResponse
    {
        [$start, $end] = FinanceService::range($request->get('start_date'), $request->get('end_date'));

        return $this->sendResponse($this->finance($request)->vatSummary($start, $end), 'VAT summary retrieved successfully');
    }

    public function receivables(Request $request): JsonResponse
    {
        return $this->sendResponse($this->finance($request)->receivables(), 'Receivables retrieved successfully');
    }

    public function payables(Request $request): JsonResponse
    {
        return $this->sendResponse($this->finance($request)->payables(), 'Payables retrieved successfully');
    }

    public function cashFlow(Request $request): JsonResponse
    {
        [$start, $end] = FinanceService::range($request->get('start_date'), $request->get('end_date'));

        return $this->sendResponse($this->finance($request)->cashFlow($start, $end), 'Cash flow retrieved successfully');
    }

    public function cashBook(Request $request): JsonResponse
    {
        [$start, $end] = FinanceService::range($request->get('start_date'), $request->get('end_date'));

        return $this->sendResponse($this->finance($request)->cashBook($start, $end), 'Cash book retrieved successfully');
    }

    /** Purchases are scoped to the store through their warehouse. */
    private function findPurchase(int $id): Purchase
    {
        $query = Purchase::whereHas('warehouse');
        if (! isAdmin()) {
            $query->whereIn('warehouse_id', getLoginUserWarehouseIds());
        }

        return $query->findOrFail($id);
    }

    public function purchasePayments(int $purchaseId): JsonResponse
    {
        $purchase = $this->findPurchase($purchaseId);

        return $this->sendResponse(
            PurchasePayment::where('purchase_id', $purchase->id)->orderBy('payment_date')->get(),
            'Supplier payments retrieved successfully'
        );
    }

    /** Record a payment to the supplier and update the purchase's paid amount / status. */
    public function storePurchasePayment(Request $request, int $purchaseId): JsonResponse
    {
        $purchase = $this->findPurchase($purchaseId);
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_type' => ['required', 'integer', 'in:1,2,3,4'],
            'reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $open = collect($this->finance($request)->payables()['documents'])->firstWhere('id', $purchase->id);
        $balance = $open['balance'] ?? 0;
        if ($balance <= 0) {
            return $this->sendError('This purchase has no outstanding balance.');
        }
        if ($data['amount'] > $balance + 0.005) {
            return $this->sendError('Amount cannot be more than the outstanding balance of ' . number_format($balance, 2) . '.');
        }

        $payment = DB::transaction(function () use ($purchase, $data, $balance) {
            $payment = PurchasePayment::create(array_merge($data, [
                'purchase_id' => $purchase->id,
                'user_id' => Auth::id(),
            ]));

            $remaining = round($balance - $data['amount'], 2);
            Purchase::whereKey($purchase->id)->update([
                'paid_amount' => round((float) $purchase->grand_total - $remaining - (float) PurchaseReturn::where('purchase_id', $purchase->id)->sum('grand_total'), 2),
                'payment_status' => $remaining <= 0.005 ? Purchase::PAID : Purchase::PARTIAL,
            ]);

            return $payment;
        });

        return $this->sendResponse($payment, 'Supplier payment recorded successfully');
    }
}
