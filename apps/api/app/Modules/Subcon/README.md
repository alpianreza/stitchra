# Modul Subcon (CMT)

Subcontracting: kirim bahan/WIP ke subcontractor, terima hasil, lacak fee jasa.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/subcon/orders/from-mo/{moId}` | `subcon.order.create` | supplier wajib type SUBCON |
| POST | `/api/subcon/orders/{id}/receive` | `subcon.order.receive` | per line; over-return ditolak |
| GET | `/api/subcon/orders/{id}` | `subcon.order.view` | detail + fees |

## Aturan bisnis
- **BR-090**: bahan keluar memposting `SUBCON_OUT` via ITS (`in_transit_subcon` ↑); return `SUBCON_IN` (↓).
- **BR-091**: subcon order terikat MO + operation.
- **BR-080**: fee per return tercatat di `subcon_fees` — masuk actual costing (Phase 8).
- Status: DRAFT → SENT → PARTIAL_RETURNED → RETURNED → CLOSED.
