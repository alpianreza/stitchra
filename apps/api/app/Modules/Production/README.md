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

## Operational convergence / legacy closure (Iteration 15)

- Legacy Marker dan Lay Roll terbukti sama-sama mengurangi `fabric_dispatch_balances.qty_consumed` serta physical Fabric Roll remaining.
- Marker completion menambah Marker usage ke `mo_material_allocations`, sedangkan Lay completion menyinkronkan/menimpa allocation dari total Lay Roll. Mixed execution dapat menghasilkan double-count, overwrite, atau undercount bergantung urutan.
- Official decision masih `NOT DEFINED / BLOCKED`; Marker maupun Lay Roll tidak dipilih secara asumtif sebagai sole authority.
- Safe guard baru hanya memblokir **new mixed consumption** pada scope MO:
  - Marker ditolak bila Lay Roll consumption sudah ada.
  - Lay Roll dan Lay completion ditolak bila Marker consumption sudah ada.
  - Historical mixed rows tetap readable; completion mutation diblokir dengan `DECISION REQUIRED` dan tidak direkonsiliasi/backfill.
- Legacy endpoint `POST cutting/orders/{cutOrder}/markers` dipertahankan. Lay, shade validation, Cut Output, dan Bundle path juga dipertahankan.
- `qty_produced` tetap non-authoritative dan tidak mendapat writer baru. Backflush tetap compatibility endpoint yang memakai Material Issue → ITS `MATERIAL_ISSUE` dan reservation, tetapi convergence target/precedence dengan ACTUAL issue tetap `BLOCKED — PRODUCTION_OUTPUT_AUTHORITY NOT DEFINED`.
- ITS tetap satu inventory movement authority. Existing source uniqueness, balance lock, non-negative controls, and domain source lineage dipertahankan; tidak dibuat stock ledger/movement baru.
- Bundle→Sewing/Finishing tetap memakai append-only production scans + WIP transfers, tanpa inventory movement baru atau alternate Finishing entity.
- QC FINAL PASS tetap satu-satunya source Packing baru; historical nullable QC source tetap compatibility/read-only dan tidak di-backfill.
- Packing→`PRODUCTION_RECEIPT`→FG→Shipment→ITS `SHIPMENT` tetap authority existing. Delivery Schedule linkage, FG/Shipment valuation, dan COGS tidak diubah.
- GR posting tetap explicit dan idempotent melalui deterministic `posting_key`; existing AR/AP/tax/payment/bank integrations tetap dipertahankan. Production valuation events tetap blocked dan tidak dibuat duplicate journal mechanism.
- Read-only endpoints:
  - `GET production/operational-integrity/authority`
  - `GET production/orders/{productionOrder}/operational-integrity`
- Production Order detail menampilkan lightweight Operational Integrity / Authority panel: authority conflict matrix, Marker/Lay evidence, legacy `qty_produced`, Backflush boundary, ITS/GL state, and truthful forward/reverse lineage.
- Migration: **NONE**. No historical backfill, destructive migration, parallel ledger, quantity writer, valuation, atau accounting ledger.

## Cross-module integrity / hardening (Iteration 16)

- Request tenant context sekarang menolak company inactive/soft-deleted sebelum company scope dipasang; existing `auth:sanctum`, `company`, permission middleware, company scopes, policies, dan service lifecycle checks tetap dipakai tanpa authorization framework baru.
- ITS tetap satu-satunya inventory movement authority. `post()`, quality release, dan adjustment menolak company inactive; posting baru juga menolak warehouse inactive.
- Deterministic ITS source key tetap `company_id × movement_type × source_document_type × source_document_id`. Identical replay mengembalikan movement existing; replay dengan line payload berbeda ditolak eksplisit sebagai `ITS_IDEMPOTENCY_CONFLICT` tanpa movement, ledger, atau balance tambahan.
- Existing stock balance key lock, row lock, transaction rollback, non-negative stock, quality hold, reservation, transfer, Material Issue/Return, Subcon, Packing receipt, dan Shipment controls tetap dipertahankan.
- Manual Journal posting dan GL period close sekarang menolak company inactive. Existing append-only journal/reversal, period lock, COA company validation, dan reversal idempotency tetap dipertahankan.
- Deterministic GL `posting_key` tetap authority existing. Identical retry tetap mengembalikan journal existing; retry source/event yang sama dengan amount, period, atau explicit journal date berbeda ditolak sebagai `GL_IDEMPOTENCY_CONFLICT` sehingga tidak ada silent period/date substitution.
- GR base-currency chain tetap `GR POSTED → ITS PURCHASE_RECEIPT → GR_RECEIPT → Journal`; tidak dibuat journal writer atau accounting framework baru.
- Iteration 15 Marker/Lay mixed-path blocking, shared Roll/dispatch locks, historical readability, dan no-backfill policy tidak diubah. Authority tetap `DECISION REQUIRED`.
- `qty_produced` tetap `LEGACY COMPATIBILITY FALLBACK — NOT AUTHORITATIVE`; tidak dibuat writer baru dan Backflush convergence tetap `BLOCKED — PRODUCTION_OUTPUT_AUTHORITY NOT DEFINED`.
- Actual Cost tetap `COMPUTED_READ_ONLY`. `WIP_VALUATION`, `FG_VALUATION`, `SHIPMENT_VALUATION`, `COGS`, dan `COST_PER_UNIT` tetap `NOT DEFINED`.
- Defined operational lineage tetap: `MO → Material Issue → ITS`, `QC FINAL PASS → Packing → PRODUCTION_RECEIPT → FG`, `Packing → Shipment → ITS SHIPMENT`, serta reverse lineage ke source existing. Legacy rows tidak direlasikan secara asumtif dan tidak di-backfill.
- API surface dan UI authority panels tidak berubah; legacy endpoints dipertahankan.
- Migration: **NONE**. Tidak ada destructive migration, historical rewrite/backfill, movement type baru, parallel ledger, atau valuation/accounting behavior baru.

## Material issue dan BR-042

- Fabric issue wajib menunjuk reservation dan roll yang sama dalam UOM pemakaian material.
- Setiap issue roll menambah `fabric_dispatch_balances.qty_dispatched` untuk MO×roll.
- Marker/Lay Roll menambah `qty_consumed`; leftover return menambah `qty_returned`.
- Constraint database menjaga `consumed + returned <= dispatched`.
- Return hanya boleh sekali per MO×roll, wajib kembali ke warehouse asal, dan harus menutup seluruh `dispatched − consumed − returned`.
- Sisa fisik roll tidak langsung dianggap leftover MO; bagian yang belum di-issue tetap sudah berada di stok warehouse sehingga tidak boleh ditambahkan lagi.
- Roll yang sudah ditutup dengan return tidak dapat di-issue ulang ke MO yang sama.
- Backflush tetap delta/idempotent terhadap prior BACKFLUSH dan memperbarui reservation serta MO allocation, tetapi ketergantungannya pada `qty_produced` dan precedence terhadap ACTUAL issue tetap LEGACY/BLOCKED sampai authority tersedia.

## Verification

Feature tests Iteration 16 disiapkan untuk active-company/warehouse blocking, ITS identical/divergent replay, no duplicate movement/ledger/balance mutation, inactive-company Journal blocking, dan GL identical/divergent retry. Existing tenant, permission, lifecycle, quantity, Marker/Lay, `qty_produced`, QC/Packing, FG/Shipment, GR posting, period, reversal, Actual Cost, valuation boundary, dan lineage suites tetap menjadi regression coverage.

Tests: **PREPARED**. Runtime tetap **DEFERRED — FINAL VERIFICATION PHASE**; jangan klaim PASS sebelum fase verifikasi final.
