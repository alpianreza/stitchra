<?php

namespace Modules\Subcon\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;
use Modules\Inventory\Services\InventoryTransactionService;
use Modules\MasterData\Models\Supplier;
use Modules\Production\Models\ProductionOrder;
use Modules\Subcon\Models\SubconOrder;
use RuntimeException;

/**
 * BR-090: bahan ke subcon = SUBCON_OUT (in_transit_subcon ↑), kembali = SUBCON_IN (↓).
 * BR-091: receipt per MO + operation. BR-080: fee jasa dilacak per return (actual costing).
 */
class SubconService
{
    public function __construct(
        private NumberingService $numbering,
        private InventoryTransactionService $its,
        private AuditService $audit,
    ) {}

    /** Buat + kirim subcon order (supplier wajib type SUBCON). */
    public function createAndSend(int $companyId, ProductionOrder $mo, int $supplierId, array $lines, array $header, User $user): SubconOrder
    {
        $supplier = Supplier::findOrFail($supplierId);
        if (! $supplier->isSubcon()) {
            throw new RuntimeException("Supplier [{$supplier->code}] bukan type SUBCON.");
        }
        if (empty($lines)) {
            throw new RuntimeException('Subcon order wajib punya minimal 1 line.');
        }

        return DB::transaction(function () use ($companyId, $mo, $supplier, $lines, $header, $user): SubconOrder {
            $order = SubconOrder::create(array_merge($header, [
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'JW'),
                'supplier_id' => $supplier->id,
                'production_order_id' => $mo->id,
                'status' => 'DRAFT',
                'sent_date' => now()->toDateString(),
                'created_by' => $user->id,
            ]));

            $itsLines = [];
            foreach ($lines as $lineData) {
                $line = $order->lines()->create($lineData);

                // BR-090: bahan pendamping keluar ke subcon (in_transit)
                if (! empty($lineData['material_id'])) {
                    $itsLines[] = [
                        'material_id' => $lineData['material_id'],
                        'warehouse_id' => $header['warehouse_id'],
                        'qty' => (float) $lineData['qty_sent'],
                        'uom_id' => $lineData['uom_id'],
                        'source_document_line_id' => $line->id,
                    ];
                }
            }

            if (! empty($itsLines)) {
                $this->its->post('SUBCON_OUT', [
                    'company_id' => $companyId,
                    'source_document_type' => 'subcon_orders',
                    'source_document_id' => $order->id,
                ], $itsLines, $user);
            }

            $order->update(['status' => 'SENT']);

            $this->audit->record('create', $order, after: ['doc_no' => $order->doc_no, 'supplier' => $supplier->code]);

            return $order->load('lines');
        });
    }

    /** Terima hasil dari subcon — BR-091: per MO+operation; fee tercatat (BR-080). */
    public function receive(SubconOrder $order, array $returns, User $user): SubconOrder
    {
        if (! in_array($order->status, ['SENT', 'PARTIAL_RETURNED'], true)) {
            throw new RuntimeException("Subcon order {$order->status} tidak bisa menerima return.");
        }

        return DB::transaction(function () use ($order, $returns, $user): SubconOrder {
            $itsLines = [];

            foreach ($returns as $ret) {
                $line = $order->lines()->lockForUpdate()->findOrFail($ret['line_id']);
                $qty = (float) $ret['qty_returned'];

                $remaining = (float) $line->qty_sent - (float) $line->qty_returned;
                if ($qty > $remaining) {
                    throw new RuntimeException("Return {$qty} melebihi sisa kirim {$remaining} (line {$line->id}).");
                }

                $line->increment('qty_returned', $qty);

                // Fee jasa (BR-080)
                $order->fees()->create([
                    'return_date' => now()->toDateString(),
                    'qty_returned' => $qty,
                    'fee_per_pcs' => (float) $order->fee_per_pcs,
                    'total_fee' => round($qty * (float) $order->fee_per_pcs, 4),
                ]);

                // Bahan pendamping kembali (bila line material)
                if ($line->material_id) {
                    $itsLines[] = [
                        'material_id' => $line->material_id,
                        'warehouse_id' => $ret['warehouse_id'],
                        'qty' => $qty,
                        'uom_id' => $line->uom_id,
                        'source_document_line_id' => $line->id,
                    ];
                }
            }

            if (! empty($itsLines)) {
                $this->its->post('SUBCON_IN', [
                    'company_id' => $order->company_id,
                    'source_document_type' => 'subcon_orders',
                    'source_document_id' => $order->id,
                ], $itsLines, $user);
            }

            $fullyReturned = $order->lines()->whereColumn('qty_returned', '<', 'qty_sent')->doesntExist();
            $order->update(['status' => $fullyReturned ? 'RETURNED' : 'PARTIAL_RETURNED']);

            $this->audit->record('update', $order, after: ['status' => $order->status, 'returns' => count($returns)]);

            return $order->fresh(['lines', 'fees']);
        });
    }
}
