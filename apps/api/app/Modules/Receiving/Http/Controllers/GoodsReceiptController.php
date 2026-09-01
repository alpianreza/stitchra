<?php

namespace Modules\Receiving\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Support\CurrentCompany;
use Modules\Receiving\Models\GoodsReceipt;
use Modules\Receiving\Services\ReceivingService;
use RuntimeException;

class GoodsReceiptController extends Controller
{
    public function __construct(private ReceivingService $service) {}
    public function index(Request $request): JsonResponse{$f=$request->validate(['per_page'=>'nullable|integer|min:1|max:100']);return response()->json(GoodsReceipt::with('purchaseOrder.supplier')->orderByDesc('id')->paginate($f['per_page']??25));}
    public function show(Request $request,GoodsReceipt $goodsReceipt): JsonResponse{return response()->json($goodsReceipt->load('lines.rolls','lines.poLine'));}
    public function store(Request $request): JsonResponse
    {
        $companyId=CurrentCompany::id();
        $data=$request->validate([
            'purchase_order_id'=>['required','integer',Rule::exists('purchase_orders','id')->where('company_id',$companyId)],'warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where('company_id',$companyId)],
            'received_date'=>'required|date','delivery_note_no'=>'nullable|string|max:64','lines'=>'required|array|min:1','lines.*.po_line_id'=>'required|integer|distinct|exists:po_lines,id','lines.*.qty_received'=>'required|numeric|min:0.0001','lines.*.location_id'=>'nullable|integer|exists:locations,id',
            'lines.*.rolls'=>'nullable|array','lines.*.rolls.*.roll_no'=>'required|string|max:64','lines.*.rolls.*.qty_buy'=>'required|numeric|min:0.0001','lines.*.rolls.*.qty_use_actual'=>'nullable|numeric|gt:0','lines.*.rolls.*.qty_meter_actual'=>'nullable|numeric|gt:0','lines.*.rolls.*.lot_no'=>'nullable|string|max:64',
            'lines.*.rolls.*.shade_group_id'=>['nullable','integer',Rule::exists('shade_groups','id')->where('company_id',$companyId)],'lines.*.rolls.*.gsm_actual'=>'nullable|numeric|gt:0','lines.*.rolls.*.width_actual_cm'=>'nullable|numeric|gt:0',
        ]);
        try{return response()->json($this->service->createAndPost($companyId,$data,$data['lines'],$request->user()),201);}catch(RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
}
