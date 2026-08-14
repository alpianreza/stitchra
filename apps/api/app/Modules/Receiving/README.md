# Modul Receiving & Inward QC

Goods Receipt (roll-level) + inward inspection + supplier return.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/receiving/grs` | `receiving.gr.create` | posting stok via ITS — **fabric wajib per roll (BR-052)** |
| GET | `/api/receiving/grs/{id}` | `receiving.gr.view` | detail + rolls |
| POST | `/api/receiving/grs/{id}/inspections` | `receiving.inspection.create` | 4-point, shrinkage, GSM, shade |
| POST | `/api/receiving/inspections/{id}/finalize` | `receiving.inspection.finalize` | PASS → release hold; FAIL → rejected |

## Aturan bisnis
- **BR-052/BR-003**: fabric dicatat per roll (`fabric_rolls`); trim/packaging lot-level.
- **BR-002**: per roll tersimpan qty beli (kg/yard), meter aktual, dan conversion_rate; default `meter = kg × 1000 / (GSM × lebar_m)`.
- **BR-004**: semua line GR masuk `QUALITY_HOLD`; available setelah inspeksi PASS; FAIL → `REJECTED_RETURNED` + supplier return memposting `PURCHASE_RETURN` via ITS.
- **BR-053**: roll membawa `shade_group_id`.
- **BR-005**: harga GR masuk moving average.
- **BR-072**: defect inspeksi dari `defect_library`, tidak free-text.
