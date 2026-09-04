# Iteration 23 — Shop Floor & Finishing Completeness

Status: **IMPLEMENTED / STATIC READBACK PASS / RUNTIME NOT RUN**

## Scope

Iteration ini menutup dua gap data operasional yang terbukti dari audit source:

1. output Sewing harian sebelumnya hanya dihitung dinamis dari seluruh scan OUT dan belum mempunyai persisted daily line-output authority;
2. named measure `FINISHING_OUT` menjumlahkan seluruh scan Finishing OUT, sehingga satu bundle dapat terhitung berulang saat melewati lebih dari satu operasi Finishing.

## Implementasi

### Schema

Migration `2026_09_04_000040_add_line_and_finishing_outputs.php` menambahkan:

- `line_outputs`: summary final Sewing output per company, MO, line, dan tanggal;
- `line_output_entries`: append-only source entries dengan unique `source_scan_id`;
- `finishing_outputs`: append-only canonical completion, unique per company+bundle dan unique source scan.

Seluruh tabel mempunyai tenant FK, operational FK, quantity constraint, dan index operasional.

### Sewing line output

- `ScanService` memanggil `ShopFloorOutputService::recordSewingFinal()` hanya ketika seluruh routing operation Sewing sudah memiliki OUT.
- Final Sewing OUT tanpa line gagal tertutup dan transaksi scan di-rollback.
- Satu source scan hanya dapat membentuk satu `line_output_entry`.
- `line_outputs.qty` dihitung dari entry canonical, bukan semua scan operasi.
- `target_qty` adalah snapshot agregat Line Loading existing untuk MO/line/tanggal yang sama; bila target tidak tersedia, target dan achievement tetap NULL.

### Canonical Finishing completion

Completion dilakukan eksplisit oleh operator setelah latest Finishing scan OUT. Service memvalidasi:

- tenant dan bundle ACTIVE;
- bundle berada di FINISHING dan MO berstatus FINISHING;
- source WIP `SEWING → FINISHING` tersedia dan quantity cocok;
- latest Finishing scan adalah OUT;
- tidak ada rework terbuka;
- belum ada canonical output untuk bundle yang sama.

Setelah sukses, satu `finishing_outputs` row dibuat, bundle berpindah ke QC, dan MO berpindah ke QC hanya bila tidak ada active/rework bundle yang masih berada sebelum QC.

### Named measure & lineage

- `FINISHING_OUT` sekarang menggunakan `SUM(finishing_outputs.qty)`.
- Scan Finishing OUT tetap disimpan sebagai append-only execution events, tetapi tidak lagi dijumlahkan langsung sebagai stage output.
- Packing-boundary lineage sekarang menganggap Finishing output tersedia hanya bila canonical `finishing_outputs` tersedia.
- `SEWING_FINAL_OUT` tetap menggunakan final routing operation sesuai BR-065.

### API

- `GET /shopfloor/finishing/completion-eligible`
- `POST /shopfloor/finishing/bundles/{bundleNo}/complete`
- `GET /shopfloor/finishing/outputs`
- `GET /shopfloor/line-outputs`

Endpoint lama dipertahankan untuk kompatibilitas.

### Frontend

- `/shopfloor/scan?stage=FINISHING`: tombol **Complete Finishing → QC**, eligibility, canonical completion indicator, dan rework blocker.
- `/shopfloor/monitor`: persisted final Sewing output per line/hari, target snapshot, achievement, WIP per MO, dan canonical Finishing outputs.

## Authority result

- `SEWING_FINAL_OUT`: satu final routing OUT per bundle.
- `FINISHING_OUT`: satu explicit canonical completion per bundle.
- Universal/generic `qty_produced` tetap non-authoritative.
- Multi-operation Finishing tidak lagi menggandakan named stage output.

## Deliberate boundaries

- Tidak menebak operasi mana yang merupakan final Finishing operation; completion adalah aksi eksplisit.
- Tidak membuat direct Bundle→Carton authority karena business rule masih NOT DEFINED.
- Tidak menambahkan downtime approval lifecycle karena category, threshold, dan approval authority belum dikunci.
- Tidak melakukan historical backfill otomatis untuk scan lama.

## Verification

Static source readback dilakukan terhadap migration, models, service, controller, routes, named-measure service, scan UI, dan monitor UI.

Sesuai instruksi runtime owner:

- migration tidak dijalankan;
- test/Pest tidak dijalankan;
- TypeScript/Next build tidak dijalankan;
- API/E2E/concurrency test tidak dijalankan.

Production readiness tetap **NO-GO** sampai runtime verification dilaksanakan oleh owner.
