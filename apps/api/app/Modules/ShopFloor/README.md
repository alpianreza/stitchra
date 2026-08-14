# Modul Shop Floor

Scan bundle per operasi (sewing/finishing) — WIP real-time tanpa input manual ganda.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/shopfloor/scans` | `shopfloor.scan.create` | scan IN/OUT bundle (BR-062) |
| GET | `/api/shopfloor/wip/{moId}` | `shopfloor.scan.view` | WIP per stage (BR-063) |
| GET | `/api/shopfloor/lines/{lineId}/daily-output` | `shopfloor.scan.view` | output harian per line |

## Aturan bisnis
- **BR-062**: OUT butuh IN; IN operasi N+1 butuh OUT operasi N (urutan routing MO); double IN ditolak. Payload `bundle_no` dari keyboard-wedge scanner.
- **BR-063**: WIP = agregasi bundle × stage dari scan (tanpa tabel agregat — anti double-count).
- **BR-012**: scan sewing pertama menaikkan MO → SEWING; finishing → FINISHING (maju saja).
- **BR-072**: rework memakai `defect_library`, tidak free-text.
- Operasi harus bagian dari routing snapshot MO (BR-030).
