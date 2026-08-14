# Modul Planning (MRP)

MRP run dari SO CONFIRMED: BOM explode → gross → netting → shortage → saran PR.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/planning/mrp-runs` | `planning.mrp.run` | jalankan MRP (BR-043) |
| GET | `/api/planning/mrp-runs/{id}` | `planning.mrp.view` | hasil netting per material |
| POST | `/api/planning/mrp-runs/{id}/convert-to-pr` | `purchasing.pr.create` | konversi shortage → PR `source=MRP` (BR-045/120) |

## Aturan bisnis
- **BR-043**: `net = gross + safety_stock − available − on_order` (available = on_hand − reserved − quality_hold; on-order dari PO APPROVED/PARTIAL_RECEIVED).
- **BR-045**: MRP READ-ONLY — tidak auto-PO/PR; planner memilih requirement lalu konversi eksplisit.
- **BR-031/032**: gross memakai `grossPerPcs()` (qty + wastage + shrinkage) dari BOM APPROVED saja (BR-023/030).
- **BR-120**: PR line menyimpan `mrp_requirement_id` (trace balik ke run).
- Setiap run tersimpan berversi (`run_no`) untuk pembandingan.
