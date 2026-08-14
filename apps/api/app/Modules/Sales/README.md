# Modul Sales

Sales Order end-to-end: Buyer PO → SO (matrix) → approval → confirm → (MRP di Phase 5).

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| GET | `/api/sales/orders` | `sales.order.view` | filter `?status=` |
| POST | `/api/sales/orders` | `sales.order.create` | matrix lines wajib (BR-020) |
| GET | `/api/sales/orders/{id}` | `sales.order.view` | detail + lines |
| POST | `/api/sales/orders/{id}/submit` | `sales.order.submit` | masuk approval flow (BR-015) |
| POST | `/api/sales/orders/{id}/confirm` | `sales.order.approve` | **BR-023 gate**: semua style wajib BOM+Routing APPROVED |

## Aturan bisnis
- Nomor SO via `NumberingService` (BR-010): `SO-YYYY-NNNNNN`.
- Matrix line: satu baris per style×colorway×size; unik per SO (BR-020).
- Amendment (BR-022): `SalesOrderService::cuttingStarted()` — hook yang membaca `production_orders` bila tabel sudah ada (Phase 5+); terkunci setelah cutting mulai.
- Status: DRAFT → SUBMITTED → APPROVED → CONFIRMED → IN_PROGRESS → CLOSED (+ REJECTED/CANCELLED).

## Event
Approval APPROVED doc_type `SO` → `SalesOrderService::markApproved()` (listener `HandleDocumentApproved`).
