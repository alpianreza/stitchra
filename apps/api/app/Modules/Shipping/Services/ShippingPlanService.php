<?php

namespace Modules\Shipping\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Packing\Models\PackingList;
use Modules\Sales\Models\DeliverySchedule;
use Modules\Sales\Models\SalesOrder;
use Modules\Shipping\Models\Shipment;
use RuntimeException;

class ShippingPlanService
{
    public function __construct(private ShipmentService $shipments, private AuditService $audit) {}

    public function index(int $companyId, User $user): array
    {
        $this->assertAccess($user, $companyId);
        return DeliverySchedule::withoutGlobalScopes()->with(['salesOrder.customer', 'shipments.lines'])
            ->where('company_id', $companyId)->orderBy('delivery_date')->orderBy('id')->get()
            ->map(fn (DeliverySchedule $schedule) => $this->summary($schedule))->values()->all();
    }

    public function salesOrders(int $companyId, User $user): array
    {
        $this->assertAccess($user, $companyId);
        return SalesOrder::withoutGlobalScopes()->with(['customer', 'lines'])
            ->where('company_id', $companyId)->whereIn('status', ['CONFIRMED', 'IN_PROGRESS'])
            ->orderBy('ex_factory_date')->get()->map(fn (SalesOrder $so) => [
                'id' => $so->id, 'doc_no' => $so->doc_no, 'status' => $so->status,
                'customer' => $so->customer, 'ex_factory_date' => $so->ex_factory_date?->toDateString(),
                'order_qty' => (float) $so->lines->sum('qty'),
                'scheduled_qty' => (float) DeliverySchedule::withoutGlobalScopes()->where('company_id', $companyId)
                    ->where('sales_order_id', $so->id)->where('status', '!=', 'CANCELLED')->sum('qty'),
            ])->values()->all();
    }

    public function create(SalesOrder $so, array $data, User $user): DeliverySchedule
    {
        return DB::transaction(function () use ($so, $data, $user) {
            $locked = SalesOrder::withoutGlobalScopes()->with('lines')->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if (! in_array($locked->status, ['CONFIRMED', 'IN_PROGRESS'], true)) throw new RuntimeException('Delivery Schedule hanya untuk SO CONFIRMED/IN_PROGRESS.');
            $qty = (float) $data['qty'];
            if ($qty <= 0) throw new RuntimeException('Planned delivery quantity wajib > 0.');
            if (strtotime($data['delivery_date']) < strtotime($locked->order_date->toDateString())) throw new RuntimeException('Delivery date tidak boleh sebelum order date.');
            DeliverySchedule::withoutGlobalScopes()->where('company_id', $locked->company_id)->where('sales_order_id', $locked->id)->lockForUpdate()->get();
            $scheduled = (float) DeliverySchedule::withoutGlobalScopes()->where('company_id', $locked->company_id)
                ->where('sales_order_id', $locked->id)->where('status', '!=', 'CANCELLED')->sum('qty');
            $ordered = (float) $locked->lines->sum('qty');
            if ($scheduled + $qty - $ordered > 0.0001) throw new RuntimeException('Cumulative Delivery Schedule melebihi quantity Sales Order.');
            $schedule = DeliverySchedule::create(['company_id' => $locked->company_id, 'sales_order_id' => $locked->id,
                'delivery_date' => $data['delivery_date'], 'qty' => $qty, 'destination' => $data['destination'] ?? null,
                'status' => 'OPEN', 'created_by' => $user->id]);
            $this->audit->record('create', $schedule, after: ['sales_order_id' => $locked->id, 'delivery_date' => $data['delivery_date'],
                'planned_qty' => $qty, 'destination' => $data['destination'] ?? null, 'authority' => 'SO_DELIVERY_SCHEDULE']);
            return $schedule->fresh(['salesOrder.customer']);
        });
    }

    public function createShipment(PackingList $packingList, array $header, User $user): Shipment
    {
        return DB::transaction(function () use ($packingList, $header, $user) {
            $pl = PackingList::withoutGlobalScopes()->whereKey($packingList->id)->lockForUpdate()->firstOrFail();
            $shipmentQty = (float) DB::table('carton_lines')->join('cartons', 'cartons.id', '=', 'carton_lines.carton_id')
                ->where('cartons.packing_list_id', $pl->id)->sum('carton_lines.qty');
            if ($shipmentQty <= 0) throw new RuntimeException('Packing List tidak memiliki quantity untuk Shipment Plan.');
            $schedules = DeliverySchedule::withoutGlobalScopes()->where('company_id', $pl->company_id)
                ->where('sales_order_id', $pl->sales_order_id)->where('status', 'OPEN')
                ->orderBy('delivery_date')->orderBy('id')->lockForUpdate()->get();
            $selected = $schedules->first(function (DeliverySchedule $schedule) use ($shipmentQty) {
                $allocated = (float) DB::table('shipment_lines')->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
                    ->where('shipments.delivery_schedule_id', $schedule->id)->where('shipments.status', '!=', 'CANCELLED')->sum('shipment_lines.qty_shipped');
                return $allocated + $shipmentQty <= (float) $schedule->qty + 0.0001;
            });
            if ($selected === null) throw new RuntimeException('Tidak ada Delivery Schedule OPEN dengan remaining quantity yang cukup untuk Packing List ini.');
            $shipment = $this->shipments->create($pl, $header, $user);
            $shipment->update(['delivery_schedule_id' => $selected->id, 'updated_by' => $user->id]);
            $allocated = (float) DB::table('shipment_lines')->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
                ->where('shipments.delivery_schedule_id', $selected->id)->where('shipments.status', '!=', 'CANCELLED')->sum('shipment_lines.qty_shipped');
            if ($allocated + 0.0001 >= (float) $selected->qty) $selected->update(['status' => 'FULFILLED', 'updated_by' => $user->id]);
            $this->audit->record('update', $shipment, after: ['delivery_schedule_id' => $selected->id,
                'planned_date' => $selected->delivery_date->toDateString(), 'ship_date' => $shipment->ship_date->toDateString(),
                'date_variance_days' => $selected->delivery_date->diffInDays($shipment->ship_date, false), 'planned_qty' => (float) $selected->qty,
                'allocated_qty' => $allocated, 'authority' => 'DELIVERY_SCHEDULE_TO_SHIPMENT_PLAN']);
            return $shipment->fresh(['lines', 'packingList', 'deliverySchedule']);
        });
    }

    private function summary(DeliverySchedule $schedule): array
    {
        $allocated = (float) $schedule->shipments->where('status', '!=', 'CANCELLED')->flatMap->lines->sum('qty_shipped');
        $shipped = (float) $schedule->shipments->where('status', 'SHIPPED')->flatMap->lines->sum('qty_shipped');
        return ['id' => $schedule->id, 'sales_order_id' => $schedule->sales_order_id, 'sales_order_no' => $schedule->salesOrder?->doc_no,
            'buyer' => $schedule->salesOrder?->customer?->name, 'delivery_date' => $schedule->delivery_date->toDateString(),
            'destination' => $schedule->destination, 'planned_qty' => (float) $schedule->qty, 'allocated_qty' => $allocated,
            'shipped_qty' => $shipped, 'remaining_qty' => max(0, (float) $schedule->qty - $allocated), 'status' => $schedule->status,
            'shipments' => $schedule->shipments->map(fn (Shipment $shipment) => ['id' => $shipment->id, 'doc_no' => $shipment->doc_no,
                'status' => $shipment->status, 'ship_date' => $shipment->ship_date->toDateString(),
                'date_variance_days' => $schedule->delivery_date->diffInDays($shipment->ship_date, false),
                'qty' => (float) $shipment->lines->sum('qty_shipped')])->values()];
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company shipment plan.');
    }
}
