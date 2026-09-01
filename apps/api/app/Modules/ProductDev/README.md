# Modul Product Development

Style development: spec, measurement chart, tech pack, sample cycle, BOM/routing versioned, dan pre-production cost sheet.

## Endpoint

| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/pd/boms` | `pd.bom.create` | versi DRAFT baru, minimal satu line |
| PUT | `/api/pd/boms/{version}` | `pd.bom.update` | hanya DRAFT (BR-030) |
| POST | `/api/pd/boms/{version}/submit` | `pd.bom.submit` | approval atomik |
| POST | `/api/pd/routings` | `pd.routing.create` | versi baru, SMV > 0 |
| POST | `/api/pd/routings/{version}/submit` | `pd.routing.submit` | approval atomik |
| POST | `/api/pd/cost-sheets/compute` | `pd.costing.create` | BOM+Routing APPROVED dan seluruh rate tersedia |
| POST | `/api/pd/cost-sheets/{id}/price` | `pd.costing.update` | FOB > 0 dan tidak di bawah cost |
| POST | `/api/pd/cost-sheets/{id}/submit` | `pd.costing.submit` | FOB wajib sudah ditetapkan |
| POST | `/api/pd/samples` | `pd.sample.create` | PROTO/FIT/PP/TOP |
| POST | `/api/pd/samples/{id}/approvals` | `pd.sample.submit` | respons buyer |

## Versioning and concurrency

- BOM dan routing header dibuat idempotently, lalu dikunci sebelum nomor versi dihitung.
- Hanya versi `DRAFT` yang dapat diedit atau disubmit.
- Submit status dan pembuatan approval request berada dalam satu transaksi; kegagalan approval tidak meninggalkan status `SUBMITTED` palsu.
- Approval versi baru mengunci header dan membuat versi APPROVED lama menjadi `OBSOLETE`.
- Cost-sheet version diserialisasi dengan style lock dan dilindungi unique index `(company_id, style_id, version)`.

## Tenant and data integrity

- Style, material, UOM, colorway, operation, dan line harus berasal dari company aktif.
- Colorway BOM harus berasal dari style yang sama.
- Route model binding BOM/routing diverifikasi kembali melalui style→company agar ID lintas tenant menghasilkan `404`.
- Qty BOM dan SMV harus lebih besar dari nol; sequence routing eksplisit tidak boleh duplikat.

## Costing invariants (BR-100)

- Costing hanya memakai BOM dan Routing APPROVED.
- Harga untuk setiap material BOM wajib tersedia dan lebih besar dari nol.
- Line cost rate dan overhead rate periode aktif wajib tersedia dan lebih besar dari nol; nilai tidak pernah diam-diam diganti `0`.
- Total SAM wajib lebih besar dari nol.
- FOB tidak boleh di bawah total manufacturing cost.

## Verification status

Regression tests tersedia untuk versioning, approval rollback, missing material price/rate, matrix mismatch, exact costing formula, dan SO confirmation gate. Hasil belum dinyatakan hijau sampai lockfile tersedia dan CI dijalankan dari clean checkout.
