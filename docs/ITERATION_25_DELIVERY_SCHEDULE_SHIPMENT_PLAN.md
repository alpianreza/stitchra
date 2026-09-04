# Iteration 25 — Delivery Schedule to Shipment Plan

Status: **IMPLEMENTED / STATIC READBACK PASS / RUNTIME NOT RUN**

## Scope

- Activate existing Delivery Schedule as a tenant-scoped operational plan from confirmed/in-progress Sales Orders.
- Persist a nullable Delivery Schedule source on Shipment for historical compatibility.
- Allocate each new Shipment Plan deterministically to the earliest OPEN schedule with sufficient remaining quantity.
- Expose planned, allocated, shipped, remaining quantity, and planned-vs-actual date variance.
- Preserve Packing List/FG receipt, tolerance, stock-out, valuation, COGS, and AR authority.

## Controls

- Cumulative active schedule quantity cannot exceed Sales Order quantity.
- Schedule date cannot precede order date.
- New Shipment creation fails closed when no OPEN schedule has enough remaining quantity.
- Delivery Schedule and Shipment share company and Sales Order through the Packing List source.
- Historical Shipment rows are not backfilled.
- Schedule status becomes FULFILLED when allocated quantity reaches planned quantity; cancelled rows are excluded.

## Implementation

Migration `2026_09_04_000042_link_delivery_schedules_to_shipments.php` adds tenant/lifecycle fields to Delivery Schedule and a nullable source FK on Shipment. `ShippingPlanService` owns schedule creation, capacity controls, deterministic allocation, summaries, and audit evidence. Existing ShipmentService remains the authority for Packing List, FG receipt, tolerance, stock movement, SO closure, and inventory controls.

The `/shipping/delivery-schedules` workbench provides SO capacity, plan entry, planned/allocated/shipped/remaining monitoring, linked shipment references, and date variance. Shared Shipping navigation links planning and execution.

## Deliberate boundaries

No automatic Shipment stock-out, no carrier booking automation, no schedule splitting across multiple schedules for one Packing List, and no change to D-08/D-10 valuation or accounting authority.

## Verification

Static readback passed for migration, models, service, controllers, routes, Shipment source relation, workbench, and navigation. Migration, Pest, TypeScript, Next build, API/E2E, and concurrency verification are NOT RUN.
