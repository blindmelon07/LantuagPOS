<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\ManageStockCollection;
use App\Http\Resources\ManageStockResource;
use App\Models\FiscalYear;
use App\Repositories\ManageStockRepository;
use Illuminate\Http\Request;

/**
 * Class UserAPIController
 */
class ManageStockAPIController extends AppBaseController
{
    private $manageStockRepository;

    public function __construct(ManageStockRepository $manageStockRepository)
    {
        $this->manageStockRepository = $manageStockRepository;
    }

    public function stockReport(Request $request): ManageStockCollection
    {
        $request->request->remove('filter');
        $perPage = getPageSize($request);
        $search = $request->get('search');
        $warehouseId = $request->get('warehouse_id');
        $stocks = \App\Models\ManageStock::with(['product.productCategory'])->whereHas('product');
        if(!isAdmin()){
            $stocks->whereIn('warehouse_id', getLoginUserWarehouseIds());
        }
        if ($warehouseId && $warehouseId != 'undefined' && $warehouseId != 'null' && $warehouseId != 'all') {
            $stocks->where('warehouse_id', $warehouseId);
        }
        if ($search && $search != 'null') {
            $stocks->where(function ($q) use ($search) {
                $q->whereHas('product', function ($query) use ($search) {
                    $query->where(function ($sub) use ($search) {
                        $sub->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('code', 'LIKE', "%{$search}%");
                    });
                })
                ->orWhereHas('product.productCategory', function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%");
                });
            });
        }

        if (isFiscalYearFilterEnabled()) {
            $fiscalYearId = $request->get('fiscal_year_id');

            $fiscalYear = !empty($fiscalYearId) && $fiscalYearId !== 'null'
                ? FiscalYear::find($fiscalYearId)
                : FiscalYear::where('is_active', true)->first();

            if ($fiscalYear) {
                $stocks->whereDate('created_at', '>=', $fiscalYear->start_date)
                    ->whereDate('created_at', '<=', $fiscalYear->end_date);
            }
        }
        $stocks = $stocks->paginate($perPage);
        ManageStockResource::usingWithCollection();

        return new ManageStockCollection($stocks);
    }
}
