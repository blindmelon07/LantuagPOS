<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Resources\FiscalYearCollection;
use App\Http\Resources\FiscalYearResource;
use App\Http\Resources\SettingResource;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\State;
use App\Models\Warehouse;
use App\Repositories\SettingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Class SettingAPIController
 */
class SettingAPIController extends AppBaseController
{
    /** @var SettingRepository */
    private $settingRepository;

    public function __construct(SettingRepository $productRepository)
    {
        $this->settingRepository = $productRepository;
    }

    public function index(): JsonResponse
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();
        $settings['store_logo'] = getStoreLogo();
        $settings['store_name'] = getActiveStoreName() ? getActiveStoreName() : ($settings['store_name'] ?? null);
        $settings['warehouse_name'] = Warehouse::whereId($settings['default_warehouse'])->first()->name ?? '';
        $settings['customer_name'] = Customer::whereId($settings['default_customer'])->first()->name ?? '';
        $settings['currency_symbol'] = Currency::whereId($settings['currency'])->first()->symbol ?? '';
        $settings['countries'] = Country::all();
        $settings['add_stock_while_product_creation'] = $settings['add_stock_while_product_creation'] ?? "1";
        $settings['decimal_places'] = $settings['decimal_places'] ?? "2";
        $settings['thousands_separator'] = $settings['thousands_separator'] ?? "2";
        $settings['decimal_separator'] = $settings['decimal_separator'] ?? "1";
        $settings['enable_nepali_datepicker'] = $settings['enable_nepali_datepicker'] ?? "0";
        $settings['receipt_other_font_style'] = $settings['receipt_other_font_style'] ?? 0;
        $settings['receipt_label_font_style'] = $settings['receipt_label_font_style'] ?? 1;
        $settings['receipt_logo_font_style'] = $settings['receipt_logo_font_style'] ?? 2;
        $settings['receipt_logo_font_size'] = $settings['receipt_logo_font_size'] ?? 1;
        $settings['receipt_label_font_size'] = $settings['receipt_label_font_size'] ?? 0;
        $settings['receipt_other_font_size'] = $settings['receipt_other_font_size'] ?? 0;
        $settings['receipt_logo_font_color'] = $settings['receipt_logo_font_color'] ?? 0;
        $settings['receipt_label_font_color'] = $settings['receipt_label_font_color'] ?? 0;
        $settings['receipt_other_font_color'] = $settings['receipt_other_font_color'] ?? 0;
        $settings['enable_fiscal_year_filter'] = $settings['enable_fiscal_year_filter'] ?? "0";
        $settings['receipt_margin'] = $settings['receipt_margin'] ?? 0;
        $settings['receipt_paper_size'] = $settings['receipt_paper_size'] ?? 1;
        $settings['receipt_thermal_size'] = $settings['receipt_thermal_size'] ?? 1;
        $settings['enable_quick_payment'] = $settings['enable_quick_payment'] ?? false;
        $settings['quick_payment_method'] = $settings['quick_payment_method'] ?? PaymentMethod::first()?->id;
        $settings['pos_shortcut_f1'] = $settings['pos_shortcut_f1'] ?? 100;
        $settings['pos_shortcut_f2'] = $settings['pos_shortcut_f2'] ?? 200;
        $settings['pos_shortcut_f3'] = $settings['pos_shortcut_f3'] ?? 500;
        $settings['pos_shortcut_f4'] = $settings['pos_shortcut_f4'] ?? 1000;
        $settings['pos_shortcut_f5'] = $settings['pos_shortcut_f5'] ?? 2000;

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'Setting data retrieved successfully.'
        );
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'store_logo' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        ]);

        $input = $request->all();
        $settings = $this->settingRepository->updateSettings($input);

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'Setting data updated successfully'
        );
    }

    public function clearCache(): JsonResponse
    {
        Artisan::call('cache:clear');

        return $this->sendSuccess(__('messages.success.cache_clear_successfully'));
    }

    public function getFrontSettingsValue(): JsonResponse
    {
        $keyName = [
            'currency',
            'is_currency_right',
            'default_customer',
            'default_warehouse',
            'date_format',
            'store_name',
            'enable_nepali_datepicker'
        ];

        if (Auth::guard('sanctum')->user() && !Auth::guard('sanctum')->user()->hasRole('superadmin')) {
            $user = Auth::guard('sanctum')->user();
            $settings = Setting::whereIn('key', $keyName)->where('tenant_id', $user->tenant_id)->pluck('value', 'key')->toArray();
            $settings['warehouse_name'] = Warehouse::whereId($settings['default_warehouse'])->first()->name ?? '';
            $settings['customer_name'] = Customer::whereId($settings['default_customer'])->first()->name ?? '';
            $settings['currency_symbol'] = Currency::whereId($settings['currency'])->first()->symbol ?? '';
            $settings['store_name'] = getActiveStoreName() ? getActiveStoreName() : ($settings['store_name'] ?? null);
            $settings['enable_nepali_datepicker'] = $settings['enable_nepali_datepicker'] ?? "0";
        }
        if (Auth::guard('sanctum')->user() && Auth::guard('sanctum')->user()->hasRole('superadmin')) {
            $settings['admin_default_currency_symbol'] = getSadminSettingValue('admin_default_currency_symbol');
            $settings['decimal_places'] = getSadminSettingValue('decimal_places');
            $settings['thousands_separator'] = getSadminSettingValue('thousands_separator');
            $settings['decimal_separator'] = getSadminSettingValue('decimal_separator');
            $settings['is_currency_right'] = getSadminSettingValue('is_currency_right');
            $settings['enable_nepali_datepicker'] = getSadminSettingValue('enable_nepali_datepicker') ?? "0";
        }
        $settings['app_logo'] = getAppLogoUrl();
        $settings['app_favicon'] = getAppFaviconUrl();
        $settings['app_name'] = getAppName();
        $settings['show_app_name_in_sidebar'] = getSadminSettingValue('show_app_name_in_sidebar');
        $settings['show_version_on_footer'] = getSadminSettingValue('show_version_on_footer');
        $settings['default_country_code'] = getSadminSettingValue('default_country_code');
        $settings['footer'] = getSadminSettingValue('footer');
        $settings['store_logo'] = getStoreLogo();


        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'value' => $settings]),
            'Setting value retrieved successfully.'
        );
    }

    public function getFrontCms(): JsonResponse
    {
        $settings['app_logo'] = getAppLogoUrl();
        $settings['app_favicon'] = getAppFaviconUrl();
        $settings['app_name'] = getAppName();
        $settings['show_app_name_in_sidebar'] = getSadminSettingValue('show_app_name_in_sidebar');
        $settings['show_version_on_footer'] = getSadminSettingValue('show_version_on_footer');
        $settings['footer'] = getSadminSettingValue('footer');
        $settings['default_country_code'] = getSadminSettingValue('default_country_code');
        $settings['is_captcha_enabled'] = getSadminSettingValue('captcha_enabled');
        if (getSadminSettingValue('captcha_enabled') == 1) {
            $settings['captcha_site_key'] = getSadminSettingValue('captcha_key');
        }

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'value' => $settings]),
            'Setting value retrieved successfully.'
        );
    }

    public function updateReceiptSetting(Request $request)
    {
        $settings = $this->settingRepository->updateReceiptSetting($request->all());

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'Setting data updated successfully'
        );
    }

    public function getStates($countryId): JsonResponse
    {
        $states = State::whereCountryId($countryId)->pluck('name');

        return $this->sendResponse(
            new SettingResource(['type' => 'states', 'value' => $states]),
            'States retrieved successfully.'
        );
    }

    public function getPosSettings(): JsonResponse
    {
        $getArray = [
            'enable_pos_click_audio',
            'click_audio',
            'show_pos_stock_product',
            'refresh_interval_seconds',
            'auto_refresh_products',
            'enable_quick_payment',
            'quick_payment_method',
            'pos_shortcut_f1',
            'pos_shortcut_f2',
            'pos_shortcut_f3',
            'pos_shortcut_f4',
            'pos_shortcut_f5',
        ];

        $settings = Setting::whereIn('key', $getArray)->pluck('value', 'key')->toArray();
        $settings['enable_pos_click_audio'] = $settings['enable_pos_click_audio'] ?? false;
        $settings['show_pos_stock_product'] = $settings['show_pos_stock_product'] ?? false;
        $settings['enable_quick_payment'] = $settings['enable_quick_payment'] ?? false;
        $settings['quick_payment_method'] = $settings['quick_payment_method'] ?? PaymentMethod::first()?->id;
        $settings['pos_shortcut_f1'] = $settings['pos_shortcut_f1'] ?? 100;
        $settings['pos_shortcut_f2'] = $settings['pos_shortcut_f2'] ?? 200;
        $settings['pos_shortcut_f3'] = $settings['pos_shortcut_f3'] ?? 500;
        $settings['pos_shortcut_f4'] = $settings['pos_shortcut_f4'] ?? 1000;
        $settings['pos_shortcut_f5'] = $settings['pos_shortcut_f5'] ?? 2000;
        if (!isset($settings['click_audio'])) {
            $settings['click_audio'] = asset('images/click_audio.mp3');
            Setting::updateOrCreate(['key' => 'click_audio'], ['value' => $settings['click_audio']]);
        }

        return $this->sendResponse(
            new SettingResource(['type' => 'settings', 'attributes' => $settings]),
            'POS Setting data retrieved successfully.'
        );
    }

    public function updatePosSettings(Request $request): JsonResponse
    {
        $request->validate([
            'click_audio' => 'nullable|file|mimes:mp3,audio/mp3|max:2048',
        ]);
        $input = $request->all();
        $this->settingRepository->updatePosSettings($input);
        return $this->sendSuccess(__('messages.success.pos_settings_updated'));
    }

    public function getDualScreenSettings(): JsonResponse
    {
        $getArray = [
            'dual_screen_header_text',
            'dual_screen_images',
        ];

        $settings = Setting::whereIn('key', $getArray)->pluck('value', 'key')->toArray();
        if (isset($settings['dual_screen_images'])) {
            $settings['dual_screen_images'] = json_decode($settings['dual_screen_images'], true);
        } else {
            $settings['dual_screen_images'] = [];
        }
        $settings['dual_screen_header_text'] = $settings['dual_screen_header_text'] ?? null;

        return $this->sendResponse(
            new SettingResource(['type' => 'dual-screen', 'attributes' => $settings]),
            'POS Setting data retrieved successfully.'
        );
    }

    public function updateDualScreenSettings(Request $request): JsonResponse
    {
        $request->validate([
            'image1' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'image2' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'image3' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'image4' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'image5' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
        ]);

        $input = $request->all();
        $this->settingRepository->updateDualScreenSettings($input);
        return $this->sendSuccess(__('messages.success.dual_screen_settings_updated'));
    }

    public function getFieldConfiguration(): JsonResponse
    {
        $keyName = [
            'customer_email_required',
            'customer_phone_number_required',
            'customer_dob_required', // false
            'customer_country_required',
            'customer_city_required',
            'customer_address_required',
            'supplier_email_required',
            'supplier_phone_number_required',
            'supplier_country_required',
            'supplier_city_required',
            'supplier_address_required',
        ];

        $settings = Setting::whereIn('key', $keyName)->pluck('value', 'key')->toArray();

        foreach ($keyName as $key) {
            if (!isset($settings[$key])) {
                if ($key == 'customer_dob_required') {
                    $settings[$key] = false;
                } else {
                    $settings[$key] = true;
                }
            }
        }

        return $this->sendResponse(['type' => 'settings', 'value' => $settings], 'Field configuration retrieved successfully.');
    }

    public function updateFieldConfiguration(Request $request): JsonResponse
    {
        $request->validate([
            'customer_email_required' => 'nullable|boolean',
            'customer_phone_number_required' => 'nullable|boolean',
            'customer_dob_required' => 'nullable|boolean',
            'customer_country_required' => 'nullable|boolean',
            'customer_city_required' => 'nullable|boolean',
            'customer_address_required' => 'nullable|boolean',
            'supplier_email_required' => 'nullable|boolean',
            'supplier_phone_number_required' => 'nullable|boolean',
            'supplier_country_required' => 'nullable|boolean',
            'supplier_city_required' => 'nullable|boolean',
            'supplier_address_required' => 'nullable|boolean',
        ]);

        $input = $request->all();
        $this->settingRepository->updateFieldConfiguration($input);

        return $this->sendSuccess('Field configuration updated successfully.');
    }

    public function getFiscalYears(Request $request): FiscalYearCollection
    {
        $perPage = getPageSize($request);
        $search = $request->input('filter.search', $request->get('search'));

        $fiscalYears = FiscalYear::query()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->when(! empty($search), function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', '%' . $search . '%');
                });
            })
            ->orderBy('start_date', 'desc')
            ->paginate($perPage);

        FiscalYearResource::usingWithCollection();

        return new FiscalYearCollection($fiscalYears);
    }

    public function updateFiscalYearFilterSetting(Request $request): JsonResponse
    {
        $request->validate([
            'enable_fiscal_year_filter' => 'required|boolean',
        ]);

        $input = $request->all();

        Setting::updateOrCreate(
            ['key' => 'enable_fiscal_year_filter', 'tenant_id' => Auth::user()->tenant_id],
            ['value' => (int) $input['enable_fiscal_year_filter']]
        );

        return $this->sendResponse([
            'enable_fiscal_year_filter' => (bool) $input['enable_fiscal_year_filter'],
        ], 'Fiscal year filter setting updated successfully.');
    }

    public function createFiscalYear(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:fiscal_years,name,NULL,id,tenant_id,' . Auth::user()->tenant_id,
            'start_date' => 'required|date',
            'end_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) use ($request) {
                    if (! $request->filled('start_date')) {
                        return;
                    }

                    $startDate = Carbon::parse($request->start_date)->toDateString();
                    $endDate = Carbon::parse($value)->toDateString();

                    $expectedEndDate = Carbon::parse($startDate)
                        ->addYear()
                        ->subDay()
                        ->toDateString();

                    if ($endDate !== $expectedEndDate) {
                        $fail("The {$attribute} must be one year minus one day after the start date.");
                        return;
                    }

                    $isExists = FiscalYear::where('tenant_id', Auth::user()->tenant_id)
                        ->where(function ($query) use ($startDate, $endDate) {
                            $query->whereBetween('start_date', [$startDate, $endDate])
                                ->orWhereBetween('end_date', [$startDate, $endDate])
                                ->orWhere(function ($q) use ($startDate, $endDate) {
                                    $q->where('start_date', '<=', $startDate)
                                        ->where('end_date', '>=', $endDate);
                                });
                        })->exists();

                    if ($isExists) {
                        $fail('This fiscal year date range already exists or overlaps with another fiscal year.');
                    }
                },
            ],
            'is_active' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $activeFiscalYearExists = FiscalYear::where('tenant_id', Auth::user()->tenant_id)
                            ->where('is_active', true)
                            ->exists();

                        if ($activeFiscalYearExists) {
                            $fail('Only one fiscal year can be active at a time.');
                        }
                    }
                },
            ],
        ]);

        $fiscalYear = $this->settingRepository->createFiscalYear($request->all());
        FiscalYearResource::usingWithCollection();

        return $this->sendResponse(new FiscalYearResource($fiscalYear), 'Fiscal year created successfully.');
    }

    public function updateActiveFiscalYear(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|exists:fiscal_years,id',
            'is_active' => 'required',
        ]);

        $fiscalYear = $this->settingRepository->updateActiveFiscalYear($request->all());
        FiscalYearResource::usingWithCollection();

        return $this->sendResponse(new FiscalYearResource($fiscalYear), 'Fiscal active updated successfully.');
    }
}
