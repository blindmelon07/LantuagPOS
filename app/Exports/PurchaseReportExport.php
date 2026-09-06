<?php

namespace App\Exports;

use App\Models\FiscalYear;
use App\Models\Purchase;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromView;

class PurchaseReportExport implements FromView
{
    public function view(): \Illuminate\Contracts\View\View
    {
        $startDate = request()->get('start_date');
        $endDate = request()->get('end_date');
        $status = request()->get('status') ?? null;

        $purchases = Purchase::with(['purchaseItems', 'warehouse', 'supplier'])->whereHas('supplier')->whereHas('warehouse');

        if (!isAdmin()) {
            $purchases->whereIn('warehouse_id', getLoginUserWarehouseIds());
        }

        if ($status && $status != 'null' && $status != 'all') {
            $purchases->where('status', $status);
        }

        if ($startDate != 'null' && $endDate != 'null' && $startDate && $endDate) {
            $purchases->whereDate('created_at', '>=', $startDate)
                ->whereDate('created_at', '<=', $endDate);
        } elseif (isFiscalYearFilterEnabled()) {
            $fiscalYearId = request()->get('fiscal_year_id');

            $fiscalYear = !empty($fiscalYearId) && $fiscalYearId !== 'null'
                ? FiscalYear::find($fiscalYearId)
                : FiscalYear::where('is_active', true)->first();

            if ($fiscalYear) {
                $purchases->whereBetween('date', [
                    Carbon::parse($fiscalYear->start_date)->startOfDay(),
                    Carbon::parse($fiscalYear->end_date)->endOfDay(),
                ]);
            }
        }

        $purchases = $purchases->orderBy('id', 'desc')->get();

        return view('excel.all-purchase-report-excel', ['purchases' => $purchases]);
    }
}
