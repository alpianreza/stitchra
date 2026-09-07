<?php

namespace Modules\Receiving\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\CurrentCompany;
use Modules\Purchasing\Models\PurchaseOrder;

/** Operational lookups without granting warehouse/QC users access to PO prices or master administration. */
class ReceivingLookupController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:128', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = PurchaseOrder::select('id', 'company_id', 'doc_no', 'supplier_id', 'status')
            ->with('supplier:id,code,name')->whereIn('status', ['APPROVED', 'PARTIAL_RECEIVED']);
        if (! empty($data['q'])) {
            $query->where('doc_no', 'like', '%'.$data['q'].'%');
        }

        return response()->json($query->orderByDesc('id')->paginate($data['per_page'] ?? 100));
    }

    public function order(PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((int) $purchaseOrder->company_id === CurrentCompany::id(), 404);
        abort_unless(in_array($purchaseOrder->status, ['APPROVED', 'PARTIAL_RECEIVED'], true), 422, 'PO tidak dapat diterima.');
        $po = $purchaseOrder->only(['id', 'doc_no', 'status', 'supplier_id']);
        $po['supplier'] = $purchaseOrder->supplier?->only(['id', 'code', 'name']);
        $po['lines'] = $purchaseOrder->lines()->select('id', 'purchase_order_id', 'material_id', 'qty', 'received_qty', 'uom_id')
            ->with('material:id,code,name,tracking_level,type')->get();

        return response()->json($po);
    }

    public function warehouses(): JsonResponse
    {
        return response()->json(['data' => DB::table('warehouses')->where('company_id', CurrentCompany::id())
            ->where('is_active', true)->whereNull('deleted_at')->whereIn('type', ['RM', 'TRIM'])
            ->orderBy('code')->get(['id', 'code', 'name', 'type'])]);
    }

    public function locations(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => 'required|integer|min:1']);

        return response()->json(['data' => DB::table('locations as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('w.company_id', CurrentCompany::id())->where('w.id', $data['warehouse_id'])
            ->where('w.is_active', true)->whereNull('w.deleted_at')->orderBy('l.code')->get(['l.id', 'l.code', 'l.name'])]);
    }

    public function defects(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:128', 'per_page' => 'nullable|integer|min:1|max:100']);
        $term = '%'.($data['q'] ?? '').'%';

        return response()->json(DB::table('defect_library')->where('company_id', CurrentCompany::id())
            ->where('is_active', true)->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('code', 'like', $term))
            ->select('id', 'code', 'name', 'severity')->orderBy('code')->paginate($data['per_page'] ?? 100));
    }
}
