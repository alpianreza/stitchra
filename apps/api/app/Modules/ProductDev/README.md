# Modul Product Development

Style development: spec, measurement chart, tech pack (S3), sample cycle, BOM & Routing versioned, pre-production cost sheet.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/pd/boms` | `pd.bom.create` | buat versi BOM baru (draft) |
| PUT | `/api/pd/boms/{version}` | `pd.bom.update` | edit lines — **hanya DRAFT** (BR-030) |
| POST | `/api/pd/boms/{version}/submit` | `pd.bom.submit` | masuk approval |
| POST | `/api/pd/routings` | `pd.routing.create` | versi routing baru + total SAM |
| POST | `/api/pd/routings/{version}/submit` | `pd.routing.submit` | |
| POST | `/api/pd/cost-sheets/compute` | `pd.costing.create` | hitung FOB dari BOM+Routing APPROVED (BR-100) |
| POST | `/api/pd/cost-sheets/{id}/price` | `pd.costing.update` | set FOB; ditolak bila < total cost |
| POST | `/api/pd/cost-sheets/{id}/submit` | `pd.costing.submit` | |
| POST | `/api/pd/samples` | `pd.sample.create` | stage PROTO/FIT/PP/TOP |
| POST | `/api/pd/samples/{id}/approvals` | `pd.sample.update` | respons buyer |

## Aturan bisnis
- **BR-030**: BOM & Routing versioned; hanya APPROVED yang dipakai MRP/costing/SO-gate; approve versi baru → versi lama OBSOLETE.
- **BR-031/032**: BOM line memuat qty_per_pcs + wastage% + shrinkage%; `grossPerPcs()` untuk MRP; consumption estimated vs actual terpisah.
- **BR-033**: SMV per operasi, total SAM dihitung otomatis.
- **BR-100**: FOB = Fabric + Trim + CM(SAM×cost/min) + OH(SAM×OH rate); cost sheet APPROVED = standard cost.
- **BR-009**: overhead per menit SAM — rate dari `overhead_rates` per periode.

## Service
`BomService`, `RoutingService`, `CostingService` — versioning & perhitungan terpusat; controller tipis.
