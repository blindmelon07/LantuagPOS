<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SalesPayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared money calculations for the KPI and Accounting screens.
 *
 * Tenant scoping: Sale/SalesPayment/PurchasePayment carry tenant_id (global scope); purchases,
 * returns and expenses are scoped through their warehouse, like the existing reports.
 * Non-admin users only see their assigned warehouses.
 */
class FinanceService
{
    private ?array $warehouseIds;

    public function __construct(?int $warehouseId = null)
    {
        $allowed = isAdmin() ? null : getLoginUserWarehouseIds();

        if ($warehouseId) {
            $this->warehouseIds = ($allowed === null || in_array($warehouseId, $allowed)) ? [$warehouseId] : [];
        } else {
            $this->warehouseIds = $allowed;
        }
    }

    public static function range(?string $start, ?string $end): array
    {
        $startDate = $start ? Carbon::parse($start)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $end ? Carbon::parse($end)->endOfDay() : Carbon::now()->endOfDay();
        if ($endDate->lt($startDate)) {
            [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
        }

        return [$startDate, $endDate];
    }

    /* ------------------------------------------------------------------ queries */

    private function scopeWarehouse(Builder $query, string $column = 'warehouse_id'): Builder
    {
        return $this->warehouseIds === null ? $query : $query->whereIn($column, $this->warehouseIds);
    }

    public function sales(Carbon $start, Carbon $end): Builder
    {
        return $this->scopeWarehouse(Sale::query()->whereBetween('date', [$start, $end]));
    }

    public function saleReturns(Carbon $start, Carbon $end): Builder
    {
        return $this->scopeWarehouse(SaleReturn::whereHas('warehouse')->whereBetween('date', [$start, $end]));
    }

    public function purchases(?Carbon $start = null, ?Carbon $end = null): Builder
    {
        $query = Purchase::whereHas('warehouse');
        if ($start && $end) {
            $query->whereBetween('date', [$start, $end]);
        }

        return $this->scopeWarehouse($query);
    }

    public function expenses(Carbon $start, Carbon $end): Builder
    {
        return $this->scopeWarehouse(Expense::whereHas('warehouse')->whereBetween('date', [$start, $end]));
    }

    /* ------------------------------------------------------------------ totals */

    /** Sales totals for a period: gross, tax, count, items and cost of goods sold. */
    public function salesSummary(Carbon $start, Carbon $end): array
    {
        $saleIds = $this->sales($start, $end)->pluck('id');
        $sales = Sale::whereIn('id', $saleIds);

        $items = DB::table('sale_items')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->whereIn('sale_items.sale_id', $saleIds)
            ->selectRaw('COALESCE(SUM(sale_items.quantity),0) qty, COALESCE(SUM(sale_items.tax_amount),0) tax,
                         COALESCE(SUM(sale_items.quantity * COALESCE(products.product_cost,0)),0) cost')
            ->first();

        $returnIds = $this->saleReturns($start, $end)->pluck('id');
        $returnItems = DB::table('sale_return_items')
            ->leftJoin('products', 'products.id', '=', 'sale_return_items.product_id')
            ->whereIn('sale_return_items.sale_return_id', $returnIds)
            ->selectRaw('COALESCE(SUM(sale_return_items.quantity),0) qty, COALESCE(SUM(sale_return_items.tax_amount),0) tax,
                         COALESCE(SUM(sale_return_items.quantity * COALESCE(products.product_cost,0)),0) cost')
            ->first();
        $returns = SaleReturn::whereIn('id', $returnIds);

        $gross = (float) $sales->sum('grand_total');
        $returnTotal = (float) $returns->sum('grand_total');
        $outputTax = (float) $sales->sum('tax_amount') + (float) $items->tax;
        $returnTax = (float) $returns->sum('tax_amount') + (float) $returnItems->tax;
        $cogs = (float) $items->cost - (float) $returnItems->cost;
        $transactions = $saleIds->count();
        $netSales = $gross - $returnTotal;
        $netRevenue = $netSales - ($outputTax - $returnTax); // excluding VAT

        return [
            'gross_sales' => round($gross, 2),
            'sales_returns' => round($returnTotal, 2),
            'net_sales' => round($netSales, 2),
            'output_tax' => round($outputTax - $returnTax, 2),
            'net_revenue' => round($netRevenue, 2),
            'cogs' => round($cogs, 2),
            'gross_profit' => round($netRevenue - $cogs, 2),
            'gross_margin' => $netRevenue > 0 ? round(($netRevenue - $cogs) / $netRevenue * 100, 1) : 0,
            'transactions' => $transactions,
            'items_sold' => round((float) $items->qty - (float) $returnItems->qty, 2),
            'average_sale' => $transactions ? round($gross / $transactions, 2) : 0,
            'items_per_sale' => $transactions ? round((float) $items->qty / $transactions, 2) : 0,
        ];
    }

    public function expensesByCategory(Carbon $start, Carbon $end): Collection
    {
        return $this->expenses($start, $end)
            ->with('expenseCategory:id,name')
            ->get(['id', 'amount', 'expense_category_id'])
            ->groupBy('expense_category_id')
            ->map(fn ($rows) => [
                'category' => $rows->first()->expenseCategory->name ?? '—',
                'amount' => round((float) $rows->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values();
    }

    public function profitAndLoss(Carbon $start, Carbon $end): array
    {
        $sales = $this->salesSummary($start, $end);
        $expenses = $this->expensesByCategory($start, $end);
        $totalExpenses = round((float) $expenses->sum('amount'), 2);
        $netProfit = round($sales['gross_profit'] - $totalExpenses, 2);

        return array_merge($sales, [
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'net_profit' => $netProfit,
            'net_margin' => $sales['net_revenue'] > 0 ? round($netProfit / $sales['net_revenue'] * 100, 1) : 0,
        ]);
    }

    /** Output VAT (sales) vs input VAT (purchases), net of returns. */
    public function vatSummary(Carbon $start, Carbon $end): array
    {
        $sales = $this->salesSummary($start, $end);

        $purchaseIds = $this->purchases($start, $end)->pluck('id');
        $purchaseTax = (float) Purchase::whereIn('id', $purchaseIds)->sum('tax_amount')
            + (float) DB::table('purchase_items')->whereIn('purchase_id', $purchaseIds)->sum('tax_amount');
        $purchaseReturnIds = $this->scopeWarehouse(PurchaseReturn::whereHas('warehouse')->whereBetween('date', [$start, $end]))->pluck('id');
        $purchaseReturnTax = (float) PurchaseReturn::whereIn('id', $purchaseReturnIds)->sum('tax_amount')
            + (float) DB::table('purchases_return_items')->whereIn('purchase_return_id', $purchaseReturnIds)->sum('tax_amount');
        $purchaseTotal = (float) Purchase::whereIn('id', $purchaseIds)->sum('grand_total')
            - (float) PurchaseReturn::whereIn('id', $purchaseReturnIds)->sum('grand_total');

        $inputTax = round($purchaseTax - $purchaseReturnTax, 2);

        return [
            'sales_total' => $sales['net_sales'],
            'output_tax' => $sales['output_tax'],
            'purchases_total' => round($purchaseTotal, 2),
            'input_tax' => $inputTax,
            'vat_payable' => round($sales['output_tax'] - $inputTax, 2),
        ];
    }

    /* ------------------------------------------------------------------ receivables / payables */

    private static function agingBucket(Carbon $date): string
    {
        $days = $date->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay());

        return match (true) {
            $days <= 30 => '0_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => 'over_90',
        };
    }

    private static function emptyBuckets(): array
    {
        return ['0_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
    }

    /** Unpaid sale balances (customer owes us), with aging. */
    public function receivables(): array
    {
        $sales = $this->scopeWarehouse(Sale::query())
            ->with('customer:id,name,phone')
            ->withSum('payments as payments_total', 'amount')
            ->get(['id', 'date', 'reference_code', 'customer_id', 'warehouse_id', 'grand_total', 'payment_status']);
        $returned = SaleReturn::whereIn('sale_id', $sales->pluck('id'))
            ->groupBy('sale_id')->selectRaw('sale_id, SUM(grand_total) total')->pluck('total', 'sale_id');

        $rows = collect();
        foreach ($sales as $sale) {
            $balance = round((float) $sale->grand_total - (float) $sale->payments_total - (float) ($returned[$sale->id] ?? 0), 2);
            if ($balance <= 0.005) {
                continue;
            }
            $date = Carbon::parse($sale->date);
            $rows->push([
                'id' => $sale->id,
                'reference' => $sale->reference_code,
                'date' => $date->toDateString(),
                'days' => $date->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
                'bucket' => self::agingBucket($date),
                'party_id' => $sale->customer_id,
                'party' => $sale->customer->name ?? '—',
                'phone' => $sale->customer->phone ?? null,
                'total' => round((float) $sale->grand_total, 2),
                'paid' => round((float) $sale->payments_total, 2),
                'balance' => $balance,
            ]);
        }

        return $this->summarizeOpenItems($rows);
    }

    /** Unpaid purchase balances (we owe suppliers), with aging. Opening stock (no payment status) is excluded. */
    public function payables(): array
    {
        $purchases = $this->purchases()
            ->whereNotNull('payment_status')
            ->where('status', '!=', Purchase::ORDERED)
            ->with('supplier:id,name,phone')
            ->get(['id', 'date', 'reference_code', 'supplier_id', 'warehouse_id', 'grand_total', 'paid_amount', 'payment_status']);
        $ids = $purchases->pluck('id');
        $payments = PurchasePayment::whereIn('purchase_id', $ids)
            ->groupBy('purchase_id')->selectRaw('purchase_id, SUM(amount) total')->pluck('total', 'purchase_id');
        $returned = PurchaseReturn::whereIn('purchase_id', $ids)
            ->groupBy('purchase_id')->selectRaw('purchase_id, SUM(grand_total) total')->pluck('total', 'purchase_id');

        $rows = collect();
        foreach ($purchases as $purchase) {
            $paid = $purchase->payment_status == Purchase::PAID
                ? (float) $purchase->grand_total
                : max((float) $purchase->paid_amount, (float) ($payments[$purchase->id] ?? 0));
            $balance = round((float) $purchase->grand_total - $paid - (float) ($returned[$purchase->id] ?? 0), 2);
            if ($balance <= 0.005) {
                continue;
            }
            $date = Carbon::parse($purchase->date);
            $rows->push([
                'id' => $purchase->id,
                'reference' => $purchase->reference_code,
                'date' => $date->toDateString(),
                'days' => $date->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
                'bucket' => self::agingBucket($date),
                'party_id' => $purchase->supplier_id,
                'party' => $purchase->supplier->name ?? '—',
                'phone' => $purchase->supplier->phone ?? null,
                'total' => round((float) $purchase->grand_total, 2),
                'paid' => round($paid, 2),
                'balance' => $balance,
            ]);
        }

        return $this->summarizeOpenItems($rows);
    }

    private function summarizeOpenItems(Collection $rows): array
    {
        $aging = self::emptyBuckets();
        foreach ($rows as $row) {
            $aging[$row['bucket']] += $row['balance'];
        }

        $parties = $rows->groupBy('party_id')->map(function ($items) {
            $buckets = self::emptyBuckets();
            foreach ($items as $item) {
                $buckets[$item['bucket']] += $item['balance'];
            }

            return [
                'party_id' => $items->first()['party_id'],
                'party' => $items->first()['party'],
                'phone' => $items->first()['phone'],
                'documents' => $items->count(),
                'balance' => round($items->sum('balance'), 2),
                'oldest_days' => $items->max('days'),
                'aging' => array_map(fn ($v) => round($v, 2), $buckets),
            ];
        })->sortByDesc('balance')->values();

        return [
            'total' => round($rows->sum('balance'), 2),
            'aging' => array_map(fn ($v) => round($v, 2), $aging),
            'parties' => $parties,
            'documents' => $rows->sortBy('date')->values(),
        ];
    }

    /* ------------------------------------------------------------------ cash */

    /**
     * Every cash movement (money in / money out) up to $end, oldest first.
     * In: customer payments. Out: supplier payments (incl. amounts paid when the purchase was entered),
     * expenses and refunds on sale returns.
     */
    public function cashMovements(?Carbon $start, Carbon $end): Collection
    {
        $between = fn ($query, $column) => $start ? $query->whereBetween($column, [$start, $end]) : $query->where($column, '<=', $end);
        $moves = collect();

        $salePayments = SalesPayment::whereHas('sale', fn ($q) => $this->scopeWarehouse($q))
            ->with('sale:id,reference_code,customer_id', 'sale.customer:id,name');
        foreach ($between($salePayments, 'payment_date')->get() as $p) {
            $moves->push(['date' => Carbon::parse($p->payment_date)->toDateString(), 'type' => 'sale_payment',
                'reference' => $p->sale->reference_code ?? '', 'party' => $p->sale->customer->name ?? '',
                'in' => (float) $p->amount, 'out' => 0.0]);
        }

        // Supplier payments recorded in Accounting.
        $purchasePayments = PurchasePayment::whereHas('purchase', fn ($q) => $this->scopeWarehouse($q->whereHas('warehouse')))
            ->with('purchase:id,reference_code,supplier_id', 'purchase.supplier:id,name');
        foreach ($between($purchasePayments, 'payment_date')->get() as $p) {
            $moves->push(['date' => Carbon::parse($p->payment_date)->toDateString(), 'type' => 'purchase_payment',
                'reference' => $p->purchase->reference_code ?? '', 'party' => $p->purchase->supplier->name ?? '',
                'in' => 0.0, 'out' => (float) $p->amount]);
        }

        // Amounts paid when the purchase itself was entered (not opening stock).
        $paymentTotals = PurchasePayment::groupBy('purchase_id')->selectRaw('purchase_id, SUM(amount) total')->pluck('total', 'purchase_id');
        $purchases = $between($this->purchases()->whereNotNull('payment_status'), 'date')->with('supplier:id,name')->get();
        foreach ($purchases as $purchase) {
            $paidUpfront = $purchase->payment_status == Purchase::PAID && empty($paymentTotals[$purchase->id])
                ? (float) $purchase->grand_total
                : (float) $purchase->paid_amount - (float) ($paymentTotals[$purchase->id] ?? 0);
            if ($paidUpfront > 0.005) {
                $moves->push(['date' => Carbon::parse($purchase->date)->toDateString(), 'type' => 'purchase',
                    'reference' => $purchase->reference_code, 'party' => $purchase->supplier->name ?? '',
                    'in' => 0.0, 'out' => round($paidUpfront, 2)]);
            }
        }

        $expenses = $between($this->scopeWarehouse(Expense::whereHas('warehouse')), 'date')->with('expenseCategory:id,name')->get();
        foreach ($expenses as $expense) {
            $moves->push(['date' => Carbon::parse($expense->date)->toDateString(), 'type' => 'expense',
                'reference' => $expense->reference_code, 'party' => $expense->title ?: ($expense->expenseCategory->name ?? ''),
                'in' => 0.0, 'out' => (float) $expense->amount]);
        }

        $refunds = $between($this->scopeWarehouse(SaleReturn::whereHas('warehouse')), 'date')->where('paid_amount', '>', 0)->with('customer:id,name')->get();
        foreach ($refunds as $return) {
            $moves->push(['date' => Carbon::parse($return->date)->toDateString(), 'type' => 'sale_refund',
                'reference' => $return->reference_code, 'party' => $return->customer->name ?? '',
                'in' => 0.0, 'out' => (float) $return->paid_amount]);
        }

        return $moves->sortBy('date')->values();
    }

    public function cashFlow(Carbon $start, Carbon $end): array
    {
        $opening = $this->cashMovements(null, $start->copy()->subSecond());
        $openingBalance = round($opening->sum('in') - $opening->sum('out'), 2);
        $moves = $this->cashMovements($start, $end);

        $byMonth = $start->diffInDays($end) > 62;
        $series = $moves->groupBy(fn ($m) => $byMonth ? substr($m['date'], 0, 7) : $m['date'])
            ->map(fn ($rows, $period) => [
                'period' => $period,
                'in' => round($rows->sum('in'), 2),
                'out' => round($rows->sum('out'), 2),
                'net' => round($rows->sum('in') - $rows->sum('out'), 2),
            ])->sortKeys()->values();

        $byType = $moves->groupBy('type')->map(fn ($rows) => [
            'in' => round($rows->sum('in'), 2),
            'out' => round($rows->sum('out'), 2),
        ]);

        $totalIn = round($moves->sum('in'), 2);
        $totalOut = round($moves->sum('out'), 2);

        return [
            'opening_balance' => $openingBalance,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net' => round($totalIn - $totalOut, 2),
            'closing_balance' => round($openingBalance + $totalIn - $totalOut, 2),
            'by_type' => $byType,
            'series' => $series,
            'grouped_by' => $byMonth ? 'month' : 'day',
        ];
    }

    public function cashBook(Carbon $start, Carbon $end): array
    {
        $opening = $this->cashMovements(null, $start->copy()->subSecond());
        $balance = round($opening->sum('in') - $opening->sum('out'), 2);
        $openingBalance = $balance;

        $rows = $this->cashMovements($start, $end)->map(function ($m) use (&$balance) {
            $balance = round($balance + $m['in'] - $m['out'], 2);

            return array_merge($m, ['in' => round($m['in'], 2), 'out' => round($m['out'], 2), 'balance' => $balance]);
        });

        return [
            'opening_balance' => $openingBalance,
            'total_in' => round($rows->sum('in'), 2),
            'total_out' => round($rows->sum('out'), 2),
            'closing_balance' => $balance,
            'rows' => $rows->values(),
        ];
    }
}
