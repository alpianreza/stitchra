# Modul Production

MO menyimpan snapshot BOM/routing dan release menghasilkan hard reservation.

## Production output / completion authority (Iteration 13)

- `PRODUCTION_OUTPUT_AUTHORITY = NOT DEFINED` untuk satu quantity output final tingkat MO.
- `production_orders.qty_produced` tetap `LEGACY COMPATIBILITY FALLBACK — NOT AUTHORITATIVE`; tidak ada operational writer yang ditemukan pada jalur Production/Cutting/Shopfloor/QC/Packing yang diinspeksi.
- `PRODUCTION_COMPLETION_LIFECYCLE = NOT DEFINED`; tidak dibuat status `COMPLETED`, completion endpoint, output writer, quantity ledger, atau backfill.
- Cut Output, Sewing final OUT, QC FINAL PASS, dan `PRODUCTION_RECEIPT` tetap authority hanya di scope tahap masing-masing. Bundle/Packing adalah derived; Finishing OUT masih partial karena terminal operation/completion marker belum didefinisikan.
- BR-080 tetap berlaku: QC FINAL PASS adalah satu-satunya Packing Input authority, tetapi bukan production-output authority.
- `QC FINAL PASS → Packing → PRODUCTION_RECEIPT → FG` tetap utuh. FG valuation, WIP valuation, COGS, dan cost-per-unit tetap `NOT DEFINED`.
- Read-only endpoint: `GET production/orders/{productionOrder}/output-authority`, terlindungi auth, company scope, `production.mo.view`, tenant-safe access, dan transaction snapshot.
- Endpoint menyajikan candidate matrix, quantity evidence, downstream classification, forward lineage, dan reverse trace; tidak melakukan write atau audit event karena tidak ada mutation.
- Migration: `NONE`.

## Material issue dan BR-042

- Fabric issue wajib menunjuk reservation dan roll yang sama dalam UOM pemakaian material.
- Setiap issue roll menambah `fabric_dispatch_balances.qty_dispatched` untuk MO×roll.
- Marker menambah `qty_consumed`; leftover return menambah `qty_returned`.
- Constraint database menjaga `consumed + returned <= dispatched`.
- Return hanya boleh sekali per MO×roll, wajib kembali ke warehouse asal, dan harus menutup seluruh `dispatched − consumed − returned`.
- Sisa fisik roll tidak langsung dianggap leftover MO; bagian yang belum di-issue tetap sudah berada di stok warehouse sehingga tidak boleh ditambahkan lagi.
- Roll yang sudah ditutup dengan return tidak dapat di-issue ulang ke MO yang sama.
- Backflush tetap delta/idempotent dan memperbarui reservation serta MO allocation, tetapi ketergantungannya pada `qty_produced` tetap diklasifikasikan LEGACY sampai authority tersedia.

## Verification

Feature tests Iteration 13 disiapkan untuk explicit undefined authority, legacy quantity isolation, stage-scope evidence, company isolation, BR-080/FG boundary, read-only behavior, dan lineage. Runtime tetap **DEFERRED — FINAL VERIFICATION PHASE**; jangan klaim PASS sebelum fase verifikasi final.
