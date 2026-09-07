# Modul Product Development

Style development: spec, measurement chart, tech pack, sample cycle, BOM/routing versioned, dan pre-production cost sheet.

## Iteration 28 completeness

- Style Spec dan Measurement/Size Spec memiliki read/history/create-new-version endpoints dan UI. Parent-style lock, existing unique version keys, dan `expected_version` menjaga revisi tidak menimpa data lama.
- Measurement memakai normalized POM × size, unit eksplisit, dan tolerance nullable; unit legacy tidak ditebak.
- Tech Pack mendukung private binary upload/download, versi baru, hash/MIME/size snapshot, dan upload replay key. File lama tidak ditimpa; metadata legacy yang belum lengkap memerlukan rekonsiliasi.
- Sample mendukung list/detail/search, source-version references, revisi per stage, dan append-only buyer responses dengan nama buyer, referensi bukti, serta internal recorder. Status baru selalu PENDING.
- `pd.sample.submit` menjadi permission tunggal untuk endpoint respons buyer existing; pemeriksaan tambahan `pd.sample.update` yang tidak selaras sudah dihapus.
- MO memilih sample APPROVED secara eksplisit dan memvalidasi ulang respons/versi pada release. Tidak ada hardcoded stage PP, otomatisasi buyer approval, atau PSO definition.
- Konfigurasi, API mapping, rollout impact, dan batas bisnis: [Iteration 28](../../../../../docs/ITERATION_28_PRODUCT_DEVELOPMENT_COMPLETENESS.md).

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

Regression tests existing tersedia untuk versioning, approval rollback, missing material price/rate, matrix mismatch, exact costing formula, dan SO confirmation gate. Iteration 28 hanya source review: migration, PHP lint, test suites, TypeScript, Next build, storage/API/browser runtime, dan concurrency **NOT RUN atas permintaan user**. Tidak ada hasil runtime hijau atau production approval yang diklaim.
