# Modul Cutting

Cut order → marker log (konsumsi kain aktual per roll) → bundle (unit tracking shop floor).

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/cutting/orders/from-mo/{moId}` | `cutting.order.create` | MO RELEASED → CUTTING (BR-012) |
| POST | `/api/cutting/orders/{id}/markers` | `cutting.marker.create` | konsumsi aktual per roll (BR-031/041) |
| POST | `/api/cutting/orders/{id}/lines/{line}/bundles` | `cutting.bundle.create` | bundle generator (BR-061) |
| POST | `/api/cutting/orders/{id}/complete` | `cutting.order.complete` | update `consumption_actual` BOM (BR-031) |

## Aturan bisnis
- **BR-031**: `consumption_actual = total meter marker / qty cut` — kolom terpisah dari `consumption_estimated`.
- **BR-041/042**: roll dikonsumsi aktual via marker log; sisa (`qty_remaining_meter`) = leftover → return via modul Production.
- **BR-061**: `bundle_no = {CUT-doc}-{line}-{seq}` unik per company; bundle membawa qty, stage, status.
- Roll harus RELEASED (lulus inward QC) sebelum dipakai marker.
