# Iteration 25 — Delivery Schedule to Shipment Plan

Status: **IMPLEMENTED / STATIC READBACK PENDING / RUNTIME NOT RUN**

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
- Delivery Schedule and Shipment must share company and Sales Order through the Packing List source.
- Historical Shipment rows are not backfilled.
- Schedule status becomes FULFILLED when allocated quantity reaches planned quantity; cancelled rows are excluded.

## Deliberate boundaries

No automatic Shipment stock-out, no carrier booking automation, no schedule splitting across multiple schedules for one Packing List, and no change to D-08/D-10 valuation or accounting authority.

## Verification

Static readback pending. Migration, Pest, TypeScript, Next build, API/E2E, and concurrency verification are NOT RUN.
