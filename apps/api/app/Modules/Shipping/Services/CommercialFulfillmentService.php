<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

/** Read-only authority view; never allocates schedules or writes fulfillment. */
class CommercialFulfillmentService
{
    public function authorityMatrix(int $companyId, User $user): array
    {
        $this->access($user, $companyId); $this->active($companyId);
        return [
            'rows' => [
                $this->boundary('SO quantity', 'SUM of SO Matrix lines', 'DEFINED'),
                $this->boundary('SO Matrix quantity', 'BR-020 style × colorway × size ordered quantity', 'DEFINED'),
                $this->boundary('Delivery Schedule quantity', 'SO-level date/qty/destination; no matrix, lifecycle, approval, or allocation', 'PARTIAL'),
                $this->boundary('Delivery Schedule → Shipment', 'No persisted relationship or allocation authority', 'NOT DEFINED'),
                $this->boundary('Packing quantity', 'Approved Packing List Carton matrix after QC FINAL PASS', 'DEFINED'),
                $this->boundary('Shipment quantity', 'Exact Carton matrix of one Packing List; constrained by SO Matrix and FG', 'DEFINED'),
                $this->boundary('Partial shipment', 'Multiple full-Packing-List shipments per SO work; schedule and partial-carton allocation are undefined', 'PARTIAL'),
                $this->boundary('Tolerance', 'BR-021/082 SO override, else buyer shipment tolerance; cumulative by SO Matrix', 'DEFINED'),
                $this->boundary('Shipment lifecycle', 'Schema DRAFT/READY/SHIPPED/CANCELLED; create and ship paths only', 'PARTIAL'),
                $this->boundary('Cancellation/reversal', 'No cancel endpoint, stock reversal, or fulfillment reversal', 'NOT DEFINED'),
                $this->boundary('Commercial Invoice', 'Dedicated PF-09 commercial document absent; Finance AR Invoice is separate', 'NOT DEFINED'),
                $this->boundary('Export documents', 'No export/customs/shipping-instruction/Bill of Lading authority', 'NOT DEFINED'),
                $this->boundary('Shipment valuation', 'SHIPMENT_VALUATION = NOT DEFINED', 'NOT DEFINED'),
                $this->boundary('COGS', 'COGS = NOT DEFINED; no COGS journal authorized', 'NOT DEFINED'),
            ],
            'quantity_candidates' => [
                $this->candidate('Packing List', 'Eligible source after approval, QC FINAL PASS, and PRODUCTION_RECEIPT', 'Shipment creation', 'DEFINED'),
                $this->candidate('Carton Matrix', 'Exact shipment-line quantity source; request cannot override it', 'Shipment lines / ITS SHIPMENT', 'DEFINED'),
                $this->candidate('SO Matrix', 'Ordered quantity plus cumulative tolerance ceiling/completion floor', 'Tolerance and SO status', 'DEFINED'),
                $this->candidate('Delivery Schedule', 'Standalone SO-level evidence with no Shipment allocation', 'Read-only context', 'NOT DEFINED'),
                $this->candidate('FG Stock', 'Availability/execution constraint, not commercial quantity source', 'ITS validation', 'DERIVED'),
            ],
            'states' => [
                'DELIVERY_SCHEDULE_SHIPMENT_AUTHORITY' => 'NOT DEFINED',
                'DELIVERY_SCHEDULE_LINK' => 'NOT DEFINED',
                'DELIVERY_SCHEDULE_FULFILLMENT' => 'NOT DEFINED',
                'PARTIAL_SHIPMENT' => 'PARTIAL',
                'SHIPMENT_TOLERANCE' => 'DEFINED_BY_BR_021_082',
                'COMMERCIAL_INVOICE' => 'NOT DEFINED',
                'EXPORT_DOCUMENT_AUTHORITY' => 'NOT DEFINED',
                'SHIPMENT_OPERATIONAL_REVERSAL' => 'NOT DEFINED',
                'SHIPMENT_ACCOUNTING_REVERSAL' => 'NOT DEFINED',
                'SHIPMENT_VALUATION' => 'NOT DEFINED',
                'COGS' => 'NOT DEFINED',
            ],
            'writes_performed' => false, 'migration' => 'NONE',
        ];
    }

    public function salesOrder(SalesOrder $salesOrder, User $user): array
    {
        return DB::transaction(function () use ($salesOrder, $user): array {
            $so = SalesOrder::withoutGlobalScopes()->with(['customer','lines.style','lines.colorway.color','lines.size'])
                ->whereKey($salesOrder->id)->firstOrFail();
            $this->access($user, (int) $so->company_id); $this->active((int) $so->company_id);
            return $this->soView($so);
        });
    }

    public function shipment(Shipment $shipment, User $user): array
    {
        return DB::transaction(function () use ($shipment, $user): array {
            $ship = Shipment::withoutGlobalScopes()->with(['lines','packingList','salesOrder.customer'])
                ->whereKey($shipment->id)->firstOrFail();
            $this->access($user, (int) $ship->company_id); $this->active((int) $ship->company_id);
            $so = SalesOrder::withoutGlobalScopes()->with(['customer','lines.style','lines.colorway.color','lines.size'])
                ->where('company_id', $ship->company_id)->whereKey($ship->sales_order_id)->firstOrFail();
            $receipt = DB::table('stock_movements')->where('company_id',$ship->company_id)->where('movement_type','PRODUCTION_RECEIPT')
                ->where('source_document_type','packing_lists')->where('source_document_id',$ship->packing_list_id)->first();
            $movement = DB::table('stock_movements')->where('company_id',$ship->company_id)->where('movement_type','SHIPMENT')
                ->where('source_document_type','shipments')->where('source_document_id',$ship->id)->first();
            $ar = DB::table('ar_invoices')->where('company_id',$ship->company_id)->where('shipment_id',$ship->id)
                ->where('status','!=','VOID')->first();
            return [
                'shipment' => ['id'=>$ship->id,'doc_no'=>$ship->doc_no,'status'=>$ship->status,'ship_date'=>$ship->ship_date?->toDateString(),
                    'qty'=>(float)$ship->lines->sum('qty_shipped'),'tolerance_check'=>$ship->tolerance_check,'over_tolerance_approved'=>(bool)$ship->over_tolerance_approved],
                'sales_order_fulfillment' => $this->soView($so),
                'packing_source' => ['id'=>$ship->packingList?->id,'doc_no'=>$ship->packingList?->doc_no,'status'=>$ship->packingList?->status,
                    'authority'=>'PACKING_LIST_CARTON_MATRIX','production_receipt_id'=>$receipt?->id],
                'shipment_lines' => $ship->lines->map(fn($line)=>['style_id'=>(int)$line->style_id,'colorway_id'=>(int)$line->colorway_id,
                    'size_id'=>(int)$line->size_id,'qty'=>(float)$line->qty_shipped])->values(),
                'its_shipment' => $movement ? ['id'=>$movement->id,'doc_no'=>$movement->doc_no,'status'=>'POSTED'] : ['status'=>'NOT_POSTED'],
                'delivery_schedule_link' => ['status'=>'NOT DEFINED','delivery_schedule_id'=>null,
                    'reason'=>'No persisted Delivery Schedule → Shipment relationship; schedules are shown only through the shared SO.'],
                'commercial_documents' => ['commercial_invoice'=>'NOT DEFINED','export_documents'=>'NOT DEFINED',
                    'ar_invoice'=>$ar ? ['id'=>$ar->id,'doc_no'=>$ar->doc_no,'status'=>$ar->status,'classification'=>'FINANCE_AR_INVOICE_NOT_COMMERCIAL_INVOICE'] : null],
                'valuation' => ['shipment'=>'NOT DEFINED','cogs'=>'NOT DEFINED','cogs_journal_allowed'=>false],
                'lineage' => [
                    'forward'=>'SO → SO Matrix → [Delivery Schedule link unavailable] → Packing List → Carton Matrix → PRODUCTION_RECEIPT → FG → Shipment → ITS SHIPMENT',
                    'reverse'=>'ITS SHIPMENT → Shipment → Packing List/Carton Matrix → [Delivery Schedule link unavailable] → SO Matrix → SO',
                ],
                'writes_performed'=>false,'migration'=>'NONE',
            ];
        });
    }

    private function soView(SalesOrder $so): array
    {
        $tolerance=(float)($so->tolerance_pct ?? $so->customer?->shipment_tolerance_pct ?? 0);
        $schedules=DB::table('delivery_schedules')->where('sales_order_id',$so->id)->orderBy('delivery_date')->orderBy('id')->get();
        $packing=DB::table('packing_lists as p')->leftJoin('cartons as c','c.packing_list_id','=','p.id')
            ->leftJoin('carton_lines as l','l.carton_id','=','c.id')->where('p.company_id',$so->company_id)->where('p.sales_order_id',$so->id)
            ->where('p.status','!=','CANCELLED')->groupBy('p.id','p.doc_no','p.status')->get(['p.id','p.doc_no','p.status',DB::raw('COALESCE(SUM(l.qty),0) qty')]);
        $matrix=$so->lines->map(function($line)use($so,$tolerance){
            $shipped=(float)DB::table('shipment_lines as l')->join('shipments as s','s.id','=','l.shipment_id')
                ->where('s.company_id',$so->company_id)->where('s.sales_order_id',$so->id)->where('s.status','SHIPPED')
                ->where('l.style_id',$line->style_id)->where('l.colorway_id',$line->colorway_id)->where('l.size_id',$line->size_id)->sum('l.qty_shipped');
            $ordered=(float)$line->qty; $max=$ordered*(1+$tolerance/100);
            return ['line_id'=>$line->id,'style_id'=>(int)$line->style_id,'style'=>$line->style?->style_no,
                'colorway_id'=>(int)$line->colorway_id,'color'=>$line->colorway?->color?->name,'size_id'=>(int)$line->size_id,'size'=>$line->size?->code,
                'ordered_qty'=>$ordered,'shipped_qty'=>$shipped,'remaining_to_order_qty'=>max(0,round($ordered-$shipped,4)),
                'commercial_ceiling_qty'=>round($max,4),'remaining_to_ceiling_qty'=>max(0,round($max-$shipped,4))];
        })->values();
        return [
            'sales_order'=>['id'=>$so->id,'doc_no'=>$so->doc_no,'status'=>$so->status,'buyer'=>$so->customer?->name,
                'ordered_qty'=>(float)$so->lines->sum('qty'),'tolerance_pct'=>$tolerance,'tolerance_source'=>$so->tolerance_pct!==null?'SALES_ORDER':'BUYER_DEFAULT'],
            'matrix'=>$matrix,
            'delivery_schedules'=>['status'=>'PARTIAL','shipment_link'=>'NOT DEFINED','rows'=>$schedules->map(fn($row)=>(array)$row)->values(),
                'scheduled_qty'=>(float)$schedules->sum('qty'),'remaining_qty'=>null,'reason'=>'Schedule fulfillment arithmetic is not authorized without allocation.'],
            'packing_lists'=>$packing->map(fn($row)=>(array)$row)->values(),
            'shipments'=>DB::table('shipments')->where('company_id',$so->company_id)->where('sales_order_id',$so->id)
                ->orderBy('id')->get(['id','doc_no','packing_list_id','ship_date','status','tolerance_check','over_tolerance_approved'])->map(fn($row)=>(array)$row)->values(),
            'partial_shipment'=>['status'=>'PARTIAL','defined'=>'Multiple Packing Lists/Shipments per SO with cumulative SO Matrix checks',
                'undefined'=>'Delivery Schedule allocation and partial-Carton shipment'],
        ];
    }

    private function boundary(string $boundary,string $authority,string $status):array{return compact('boundary','authority','status');}
    private function candidate(string $candidate,string $authority,string $usedBy,string $status):array{return ['candidate'=>$candidate,'existing_authority'=>$authority,'used_by'=>$usedBy,'status'=>$status];}
    private function access(User $user,int $companyId):void{if((int)$user->company_id!==$companyId&&!$user->companies()->whereKey($companyId)->exists())throw new RuntimeException('User tidak memiliki akses ke company fulfillment.');}
    private function active(int $companyId):void{if(!DB::table('companies')->where('id',$companyId)->where('is_active',true)->whereNull('deleted_at')->exists())throw new RuntimeException('Company fulfillment tidak aktif.');}
}
