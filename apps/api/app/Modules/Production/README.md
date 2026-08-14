# Modul Production (Manufacturing Order)

MO per style dari SO CONFIRMED; release = hard reservation; lifecycle shop floor.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/production/orders/from-so/{soId}` | `production.order.create` | satu MO per style; snapshot BOM/Routing APPROVED (BR-030) |
| POST | `/api/production/orders/{id}/release` | `production.order.release` | **hard reservation** (BR-060); shortage → 422 + daftar kurang (BR-040) |
| POST | `/api/production/orders/{id}/unrelease` | `production.order.release` | lepas reservasi → PLANNED |
| GET | `/api/production/orders` | `production.order.view` | filter `?status=` |
| GET | `/api/production/orders/{id}` | `production.order.view` | detail + alokasi material |

## Aturan bisnis
- **BR-060**: release membuat `stock_reservations` + menaikkan `stock_balances.reserved` — atomic (shortage di satu material ⇒ seluruh release batal).
- **BR-040**: shortage tidak auto-adjust; error berisi daftar kurang per material untuk planner.
- **BR-041**: `is_backflush` dari BOM line tersimpan di alokasi (dipakai Phase 6 saat issue).
- **BR-030**: `bom_version_id`/`routing_version_id` snapshot — revisi BOM baru tidak mengubah MO berjalan.
- Lifecycle: PLANNED → RELEASED → CUTTING → SEWING → FINISHING → QC → PACKED → CLOSED (BR-012).
- Issue material & return leftover diimplementasikan Phase 6 (BR-041/042).
