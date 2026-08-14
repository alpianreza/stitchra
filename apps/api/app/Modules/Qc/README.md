# Modul QC

Inspeksi kualitas: inline, endline, dan final (sampling AQL per buyer).

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/qc/mo/{moId}/inspections` | `qc.inspection.create` | FINAL menghitung sample+Ac/Re otomatis |
| POST | `/api/qc/inspections/{id}/defects` | `qc.inspection.update` | defect dari library (BR-072) |
| POST | `/api/qc/inspections/{id}/finalize` | `qc.inspection.finalize` | FINAL: verdict AQL otomatis; lainnya manual |

## Aturan bisnis
- **BR-008**: AQL per buyer dari `customer_aql_configs`, di-snapshot ke inspeksi (default G-II, major 2.5, minor 4.0, critical 0).
- **BR-071**: FINAL = sampling ISO 2859-1 G-II (`AqlSamplingService`): lot → sample size → Ac/Re; critical selalu Ac=0.
- **BR-073**: FAIL → verdict REWORK; inspeksi ulang otomatis cycle+1; inspeksi ber-verdict tidak bisa diubah.
- **BR-082**: QC FINAL PASS adalah syarat finalize packing list.
