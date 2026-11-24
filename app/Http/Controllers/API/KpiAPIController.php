<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\KpiTarget;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KpiAPIController extends AppBaseController
{
    /** Headline KPIs for a period, compared with the previous period of the same length. */
    public function summary(Request $request): JsonResponse
    {
        [$start, $end] = FinanceService::range($request->get('start_date'), $request->get('end_date'));
        $finance = new FinanceService($request->integer('warehouse_id') ?: null);

        $days = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();

        $current = $finance->salesSummary($start, $end);
        $previous = $finance->salesSummary($prevStart, $prevEnd);
        $expenses = round((float) $finance->expenses($start, $end)->sum('amount'), 2);
        $current['net_profit'] = round($current['gross_profit'] - $expenses, 2);
        $previous['net_profit'] = round($previous['gross_profit'] - (float) $finance->expenses($prevStart, $prevEnd)->sum('amount'), 2);

        $change = [];
        foreach ($current as $key => $value) {
            $old = $previous[$key] ?? 0;
            $change[$key] = $old != 0 ? round(($value - $old) / abs($old) * 100, 1) : null;
        }

        // Inventory: current stock value and turnover for the period.
        $stockValue = (float) DB::table('manage_stocks')
            ->join('products', 'products.id', '=', 'manage_stocks.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'manage_stocks.warehouse_id')
            ->where('warehouses.tenant_id', Auth::user()->tenant_id)
            ->when($request->integer('warehouse_id'), fn ($q, $id) => $q->where('manage_stocks.warehouse_id', $id))
            ->when(! isAdmin(), fn ($q) => $q->whereIn('manage_stocks.warehouse_id', getLoginUserWarehouseIds()))
            ->sum(DB::raw('GREATEST(manage_stocks.quantity,0) * products.product_cost'));
        $turnover = $stockValue > 0 ? round($current['cogs'] / $stockValue, 2) : null;
        $dailyCogs = $current['cogs'] / $days;

        return $this->sendResponse([
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days],
            'previous_period' => ['start' => $prevStart->toDateString(), 'end' => $prevEnd->toDateString()],
            'current' => array_merge($current, ['expenses' => $expenses]),
            'previous' => $previous,
            'change' => $change,
            'inventory' => [
                'stock_value' => round($stockValue, 2),
                'turnover' => $turnover,
                'days_of_stock' => $dailyCogs > 0 ? round($stockValue / $dailyCogs) : null,
            ],
            'series' => $this->series($finance, $start, $end),
            'by_cashier' => $this->breakdown($finance, $start, $end, 'user_id'),
            'by_warehouse' => $this->breakdown($finance, $start, $end, 'warehouse_id'),
            'targets' => $this->targetProgress($finance, $request->get('period') ?: Carbon::now()->format('Y-m')),
        ], 'KPI retrieved successfully');
    }

    private function series(FinanceService $finance, Carbon $start, Carbon $end): array
    {
        $byMonth = $start->diffInDays($end) > 62;
        $format = $byMonth ? '%Y-%m' : '%Y-%m-%d';

        $rows = $finance->sales($start, $end)
            ->selectRaw("DATE_FORMAT(date, '$format') period, SUM(grand_total) sales, COUNT(*) transactions")
            ->groupBy('period')->orderBy('period')->get()->keyBy('period');

        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $byMonth ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $series[] = [
                'period' => $key,
                'sales' => round((float) ($rows[$key]->sales ?? 0), 2),
                'transactions' => (int) ($rows[$key]->transactions ?? 0),
            ];
            $byMonth ? $cursor->addMonthNoOverflow()->startOfMonth() : $cursor->addDay();
        }

        return $series;
    }

    private function breakdown(FinanceService $finance, Carbon $start, Carbon $end, string $column): array
    {
        $rows = $finance->sales($start, $end)
            ->selectRaw("$column id, SUM(grand_total) sales, COUNT(*) transactions")
            ->groupBy($column)->orderByDesc('sales')->get();

        $names = $column === 'user_id'
            ? User::withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))->get()->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name . ' ' . $u->last_name)])
            : Warehouse::whereIn('id', $rows->pluck('id'))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'name' => $names[$row->id] ?? '—',
            'sales' => round((float) $row->sales, 2),
            'transactions' => (int) $row->transactions,
            'average_sale' => $row->transactions ? round($row->sales / $row->transactions, 2) : 0,
        ])->values()->all();
    }

    /** Month-to-date actuals against each target of the given month. */
    private function targetProgress(FinanceService $finance, string $period): array
    {
        $monthStart = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $actualByUser = $finance->sales($monthStart, $monthEnd)
            ->selectRaw('user_id, SUM(grand_total) sales, COUNT(*) transactions')
            ->groupBy('user_id')->get()->keyBy('user_id');
        $storeSales = (float) $actualByUser->sum('sales');
        $storeTransactions = (int) $actualByUser->sum('transactions');

        $elapsed = Carbon::now()->between($monthStart, $monthEnd)
            ? Carbon::now()->day / $monthStart->daysInMonth
            : (Carbon::now()->gt($monthEnd) ? 1 : 0);

        return KpiTarget::with('user:id,first_name,last_name')->where('period', $period)->get()
            ->sortBy(fn ($t) => $t->user_id === null ? 0 : 1)
            ->map(function (KpiTarget $target) use ($actualByUser, $storeSales, $storeTransactions, $elapsed) {
                $sales = $target->user_id === null ? $storeSales : (float) ($actualByUser[$target->user_id]->sales ?? 0);
                $transactions = $target->user_id === null ? $storeTransactions : (int) ($actualByUser[$target->user_id]->transactions ?? 0);

                return [
                    'id' => $target->id,
                    'user_id' => $target->user_id,
                    'name' => $target->user_id === null ? null : trim(($target->user->first_name ?? '') . ' ' . ($target->user->last_name ?? '')),
                    'sales_target' => $target->sales_target,
                    'sales_actual' => round($sales, 2),
                    'sales_progress' => $target->sales_target > 0 ? round($sales / $target->sales_target * 100, 1) : null,
                    'transactions_target' => $target->transactions_target,
                    'transactions_actual' => $transactions,
                    'transactions_progress' => $target->transactions_target ? round($transactions / $target->transactions_target * 100, 1) : null,
                    // Where the store should be by today if sales were spread evenly over the month.
                    'expected_progress' => round($elapsed * 100, 1),
                ];
            })->values()->all();
    }

    public function targets(Request $request): JsonResponse
    {
        $period = $request->get('period') ?: Carbon::now()->format('Y-m');

        return $this->sendResponse([
            'period' => $period,
            'targets' => KpiTarget::where('period', $period)->get(['id', 'period', 'user_id', 'sales_target', 'transactions_target']),
            'users' => User::get(['id', 'first_name', 'last_name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)]),
        ], 'KPI targets retrieved successfully');
    }

    public function saveTarget(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'sales_target' => ['required', 'numeric', 'min:0'],
            'transactions_target' => ['nullable', 'integer', 'min:0'],
        ]);

        $target = KpiTarget::updateOrCreate(
            ['tenant_id' => $tenantId, 'period' => $data['period'], 'user_id' => $data['user_id'] ?? null],
            ['sales_target' => $data['sales_target'], 'transactions_target' => $data['transactions_target'] ?? null]
        );

        return $this->sendResponse($target, 'KPI target saved successfully');
    }

    public function deleteTarget(int $id): JsonResponse
    {
        KpiTarget::findOrFail($id)->delete();

        return $this->sendSuccess('KPI target deleted successfully');
    }
}
