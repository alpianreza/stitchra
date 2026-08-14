<?php

namespace Modules\MasterData\Support;

use Modules\MasterData\Models\ChartOfAccount;
use Modules\MasterData\Models\Color;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\DefectLibrary;
use Modules\MasterData\Models\Employee;
use Modules\MasterData\Models\ExchangeRate;
use Modules\MasterData\Models\Line;
use Modules\MasterData\Models\LineCostRate;
use Modules\MasterData\Models\Machine;
use Modules\MasterData\Models\Material;
use Modules\MasterData\Models\Operation;
use Modules\MasterData\Models\OverheadRate;
use Modules\MasterData\Models\ShadeGroup;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\SizeRange;
use Modules\MasterData\Models\Style;
use Modules\MasterData\Models\Supplier;
use Modules\MasterData\Models\Uom;
use Modules\MasterData\Models\Warehouse;

/**
 * Registry entitas master data: slug → model, kode permission, validasi.
 * Master data = CRUD murni (KISS); modul bisnis kompleks memakai controller khusus.
 */
class MasterDataRegistry
{
    /** @return array<string, array{model: class-string, entity: string, rules: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            'customers' => [
                'model' => Customer::class,
                'entity' => 'customer',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'brand' => 'nullable|string|max:255',
                    'country' => 'nullable|string|size:2',
                    'currency' => 'nullable|string|size:3',
                    'payment_term' => 'nullable|string|max:64',
                    'incoterm' => 'nullable|string|max:8',
                    'shipment_tolerance_pct' => 'nullable|numeric|min:0|max:100',
                ],
            ],
            'suppliers' => [
                'model' => Supplier::class,
                'entity' => 'supplier',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'type' => 'required|in:FABRIC,TRIM,PACKAGING,SUBCON',
                    'lead_time_days' => 'nullable|integer|min:0',
                    'currency' => 'nullable|string|size:3',
                    'payment_term' => 'nullable|string|max:64',
                ],
            ],
            'employees' => [
                'model' => Employee::class,
                'entity' => 'employee',
                'rules' => [
                    'nik' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'section' => 'nullable|string|max:64',
                    'line_id' => 'nullable|integer|exists:lines,id',
                    'skill' => 'nullable|string|max:255',
                    'is_operator' => 'boolean',
                ],
            ],
            'styles' => [
                'model' => Style::class,
                'entity' => 'style',
                'rules' => [
                    'style_no' => 'required|string|max:64',
                    'buyer_style_ref' => 'nullable|string|max:255',
                    'customer_id' => 'nullable|integer|exists:customers,id',
                    'season' => 'nullable|string|max:32',
                    'category' => 'required|in:WOVEN,KNIT,OTHER',
                    'product_group' => 'nullable|string|max:64',
                    'lifecycle' => 'nullable|in:DEVELOPMENT,ACTIVE,DISCONTINUED',
                ],
            ],
            'colors' => [
                'model' => Color::class,
                'entity' => 'style',
                'rules' => ['code' => 'required|string|max:32', 'name' => 'required|string|max:255', 'buyer_color_name' => 'nullable|string|max:255'],
            ],
            'colorways' => [
                'model' => Colorway::class,
                'entity' => 'style',
                'rules' => [
                    'style_id' => 'required|integer|exists:styles,id',
                    'color_id' => 'required|integer|exists:colors,id',
                    'lab_dip_ref' => 'nullable|string|max:255',
                    'shade_group_id' => 'nullable|integer|exists:shade_groups,id',
                ],
            ],
            'shade-groups' => [
                'model' => ShadeGroup::class,
                'entity' => 'style',
                'rules' => ['code' => 'required|string|max:32', 'name' => 'nullable|string|max:255'],
            ],
            'sizes' => [
                'model' => Size::class,
                'entity' => 'style',
                'rules' => ['code' => 'required|string|max:16', 'sort_order' => 'nullable|integer|min:0'],
            ],
            'size-ranges' => [
                'model' => SizeRange::class,
                'entity' => 'style',
                'rules' => ['code' => 'required|string|max:32', 'name' => 'nullable|string|max:255'],
            ],
            'uoms' => [
                'model' => Uom::class,
                'entity' => 'uom',
                'rules' => ['code' => 'required|string|max:16', 'name' => 'required|string|max:255'],
            ],
            'materials' => [
                'model' => Material::class,
                'entity' => 'material',
                'rules' => [
                    'code' => 'required|string|max:64',
                    'name' => 'required|string|max:255',
                    'type' => 'required|in:FABRIC,TRIM,PACKAGING',
                    'material_class' => 'nullable|string|max:32',
                    'composition' => 'nullable|string|max:255',
                    'construction' => 'nullable|string|max:255',
                    'gsm' => 'nullable|numeric|min:0',
                    'width_cm' => 'nullable|numeric|min:0',
                    'shrinkage_std_pct' => 'nullable|numeric|min:0|max:100',
                    'buy_uom_id' => 'nullable|integer|exists:uoms,id',
                    'use_uom_id' => 'nullable|integer|exists:uoms,id',
                    'tracking_level' => 'nullable|in:ROLL,LOT',
                    'safety_stock_qty' => 'nullable|numeric|min:0',
                ],
            ],
            'warehouses' => [
                'model' => Warehouse::class,
                'entity' => 'warehouse',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'type' => 'required|in:RM,WIP,FG,TRIM,SUBCON_VIRTUAL',
                    'factory_id' => 'nullable|integer|exists:factories,id',
                ],
            ],
            'lines' => [
                'model' => Line::class,
                'entity' => 'line',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'section' => 'nullable|string|max:32',
                    'capacity_std' => 'nullable|integer|min:0',
                    'manpower_std' => 'nullable|integer|min:0',
                ],
            ],
            'machines' => [
                'model' => Machine::class,
                'entity' => 'machine',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'type' => 'required|string|max:64',
                    'line_id' => 'nullable|integer|exists:lines,id',
                ],
            ],
            'operations' => [
                'model' => Operation::class,
                'entity' => 'operation',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'machine_type' => 'nullable|string|max:64',
                    'grade' => 'nullable|string|max:8',
                ],
            ],
            'defect-library' => [
                'model' => DefectLibrary::class,
                'entity' => 'defect',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'category' => 'required|in:FABRIC,WORKMANSHIP,MEASUREMENT,PACKAGING,OTHER',
                    'severity' => 'required|in:CRITICAL,MAJOR,MINOR',
                ],
            ],
            'chart-of-accounts' => [
                'model' => ChartOfAccount::class,
                'entity' => 'finance',
                'rules' => [
                    'code' => 'required|string|max:32',
                    'name' => 'required|string|max:255',
                    'type' => 'required|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE',
                    'normal_balance' => 'required|in:DEBIT,CREDIT',
                    'parent_id' => 'nullable|integer|exists:chart_of_accounts,id',
                ],
            ],
            'currencies' => [
                'model' => Currency::class,
                'entity' => 'finance',
                'rules' => ['code' => 'required|string|size:3', 'name' => 'required|string|max:255', 'symbol' => 'nullable|string|max:8'],
            ],
            'exchange-rates' => [
                'model' => ExchangeRate::class,
                'entity' => 'finance',
                'rules' => ['currency_id' => 'required|integer|exists:currencies,id', 'rate_date' => 'required|date', 'rate' => 'required|numeric|min:0'],
            ],
            'overhead-rates' => [
                'model' => OverheadRate::class,
                'entity' => 'finance',
                'rules' => ['period' => 'required|string|max:7', 'rate_per_minute' => 'required|numeric|min:0'],
            ],
            'line-cost-rates' => [
                'model' => LineCostRate::class,
                'entity' => 'finance',
                'rules' => ['line_id' => 'required|integer|exists:lines,id', 'period' => 'required|string|max:7', 'cost_per_minute' => 'required|numeric|min:0'],
            ],
        ];
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }
}
