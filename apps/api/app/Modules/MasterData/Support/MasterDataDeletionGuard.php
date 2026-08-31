<?php

namespace Modules\MasterData\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Prevent soft deletion when a master record is already referenced. */
class MasterDataDeletionGuard
{
    /** @var array<string, array<int, array{0: string, 1: string}>> */
    private const REFERENCES = [
        'customers' => [
            ['customer_aql_configs', 'customer_id'], ['styles', 'customer_id'],
            ['sales_orders', 'customer_id'], ['ar_invoices', 'customer_id'],
        ],
        'suppliers' => [
            ['purchase_orders', 'supplier_id'], ['supplier_invoices', 'supplier_id'],
        ],
        'styles' => [
            ['colorways', 'style_id'], ['boms', 'style_id'], ['routings', 'style_id'],
            ['sales_order_lines', 'style_id'], ['cost_sheets', 'style_id'],
        ],
        'colors' => [['colorways', 'color_id']],
        'colorways' => [
            ['bom_lines', 'colorway_id'], ['sales_order_lines', 'colorway_id'],
        ],
        'uoms' => [
            ['materials', 'buy_uom_id'], ['materials', 'use_uom_id'],
            ['bom_lines', 'uom_id'], ['stock_ledger', 'uom_id'],
            ['purchase_order_lines', 'uom_id'], ['goods_receipt_lines', 'uom_id'],
        ],
        'materials' => [
            ['bom_lines', 'material_id'], ['material_uom_conversions', 'material_id'],
            ['stock_ledger', 'material_id'], ['stock_balances', 'material_id'],
            ['stock_reservations', 'material_id'], ['stock_transfer_lines', 'material_id'],
            ['stock_adjustment_lines', 'material_id'], ['stock_opname_lines', 'material_id'],
            ['purchase_request_lines', 'material_id'], ['purchase_order_lines', 'material_id'],
            ['goods_receipt_lines', 'material_id'],
        ],
        'warehouses' => [
            ['locations', 'warehouse_id'], ['stock_ledger', 'warehouse_id'],
            ['stock_balances', 'warehouse_id'], ['stock_reservations', 'warehouse_id'],
            ['stock_transfers', 'from_warehouse_id'], ['stock_transfers', 'to_warehouse_id'],
            ['goods_receipts', 'warehouse_id'],
        ],
        'lines' => [
            ['employees', 'line_id'], ['machines', 'line_id'], ['line_cost_rates', 'line_id'],
            ['production_orders', 'line_id'], ['production_scans', 'line_id'],
        ],
        'operations' => [
            ['routing_operations', 'operation_id'], ['production_scans', 'operation_id'],
        ],
        'currencies' => [['exchange_rates', 'currency_id']],
    ];

    public static function assertDeletable(Model $record): void
    {
        foreach (self::REFERENCES[$record->getTable()] ?? [] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $record->getKey())->exists()) {
                abort(409, "Master [{$record->getTable()}] sudah dipakai dan tidak dapat dihapus.");
            }
        }
    }
}
