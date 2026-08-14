# Modul Shipping

Shipping instruction → shipment dengan cek toleransi buyer; FG keluar via ITS.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/shipping/shipments/from-pl/{plId}` | `shipping.shipment.create` | dari packing list APPROVED |
| POST | `/api/shipping/shipments/{id}/approve-over-tolerance` | `shipping.shipment.approve` | BR-021 |
| POST | `/api/shipping/shipments/{id}/ship` | `shipping.shipment.ship` | ITS `SHIPMENT` (FG ↓) |
| GET | `/api/shipping/shipments/{id}` | `shipping.shipment.view` | |

## Aturan bisnis
- **BR-021**: `tolerance_check` (OK/OVER/UNDER) dihitung saat create; ship di luar toleransi wajib `approveOverTolerance` (audit trail).
- **BR-013/006**: ship memposting `SHIPMENT` via ITS — FG tidak pernah negatif.
- SO otomatis CLOSED bila total terkirim ≥ order − toleransi; sebaliknya IN_PROGRESS.
