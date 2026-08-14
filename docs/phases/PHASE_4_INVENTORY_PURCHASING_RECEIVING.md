# PHASE 4 — INVENTORY, PURCHASING, RECEIVING (Implementation Plan)

> **Dasar:** ROADMAP v1.1 §Phase 4 (fase paling kritis), MODULE_MAP §2.6–2.8, DATABASE_BLUEPRINT §3.6–3.8, PROCESS_FLOW PF-03/PF-12, BUSINESS_RULES BR-001..006, BR-013, BR-040/041/042/043, BR-050/051/052/053, DEC-2026-08-13-01 s/d DEC-2026-08-14-01
> **Prasyarat:** Phase 1 ✅ (Core), Phase 2 ✅ (Master Data), Phase 3 ✅ (BOM untuk MRP/issue nanti).

## Objective
Mesin inventory real-time berbasis stock ledger (satu-satunya sumber kebenaran), siklus purchasing lengkap (PR→RFQ→PO→GR→3-way match), dan receiving roll-level dengan inward QC + quality hold.

## Current State
Master data aktif. Belum ada stok, PO, atau penerimaan. Semua perubahan stok nol — ledger kosong.

## Scope (dari ROADMAP Phase 4)
1. **Inventory engine:** `stock_ledger` (append-only, qty+cost per transaksi BR-005/013), `stock_balances` (materialized, CHECK ≥ 0 BR-006), `stock_movements` (header dokumen), **Inventory Transaction Service (ITS)** — satu-satunya pintu tulis, atomic (dokumen+lines+ledger+saldo dalam SATU transaksi DB).
2. **Reservation engine:** hard reservation saat MO release (BR-006/060); tabel `stock_reservations` (FK ke production_orders ditambahkan di Phase 5 — kolom disiapkan).
3. **Inventory ops:** transfer (TRANSFER_OUT+IN), adjustment ber-approval (BR-017), opname + variance (PF-12).
4. **Purchasing:** PR (dari MRP Phase 5 / manual), RFQ + quotation comparison, PO (approval berjenjang by nilai — approval matrix BR-015), supplier invoice + **3-way match** (PO–GR–invoice, BR-050); partial receiving (BR-051).
5. **Receiving:** GR **roll-level untuk fabric** (BR-003/052), dual UOM per roll + konversi tersimpan (BR-002), status awal `QUALITY_HOLD` (BR-004); inward inspection (4-point, shrinkage, GSM aktual, shade); PASS → release hold (`QUALITY_RELEASE`); FAIL → supplier return (`PURCHASE_RETURN`) + claim.
6. **Moving average valuation** (BR-005): avg_cost diupdate tiap penerimaan; ledger menyimpan cost per transaksi.

## Business Rules yang Diimplementasikan
BR-001 (ownership), BR-002 (dual UOM per roll), BR-003 (roll vs lot), BR-004 (quality hold), BR-005 (moving average), BR-006 (available = on_hand − reserved − quality_hold; no negative), BR-013 (ITS satu pintu, atomic), BR-040 (shortage manual planner), BR-041 (issue aktual fabric / backflush trim), BR-042 (leftover return), BR-043 (safety stock), BR-050 (3-way match), BR-051 (partial), BR-052 (GR per roll), BR-053 (shade group pada roll).

## Technical Design
- `Modules/Inventory/Services/InventoryTransactionService` (ITS):
  ```
  post(document, lines[]): DB::transaction {
      1. insert stock_movements (header, nomor via NumberingService)
      2. per line: insert stock_ledger (qty_in/qty_out, unit_cost)
      3. upsert stock_balances (lock row FOR UPDATE; CHECK ≥ 0 menolak negatif)
      4. update avg_cost (moving average) untuk penerimaan
  } — kegagalan apa pun ⇒ ROLLBACK total (FASE 20 master prompt)
  ```
- Modul lain TIDAK menulis `stock_*` langsung — selalu via ITS (BR-013, I-01).
- `moving average`: on receipt → `avg_cost = (old_qty×old_avg + in_qty×in_cost) / (old_qty + in_qty)`.
- `fabric_rolls`: per roll — roll_no unik per company, qty_beli (UOM beli), qty_meter_actual, conversion_rate tersimpan, gsm/width aktual, shade_group, status (QUALITY_HOLD/RELEASED/REJECTED_RETURNED).
- 3-way match (BR-050): invoice vs PO price (toleransi % dari approval matrix) vs GR qty → MATCHED/MISMATCH.
- Locking: counter & balances via `lockForUpdate()`; test konkurensi wajib.

## Files To Change (batch)
1. ✅ Plan ini
2. Migrations inventory: stock_ledger, stock_balances, stock_movements, stock_reservations
3. Migrations inventory ops: stock_transfers(+lines), stock_adjustments(+lines), stock_opnames(+lines)
4. Migrations purchasing: purchase_requests(+lines), rfqs, quotations(+lines), purchase_orders(+lines), supplier_invoices(+lines)
5. Migrations receiving: goods_receipts, gr_lines, fabric_rolls, inward_inspections(+lines), supplier_returns
6. Models + ITS + MovingAverage
7. Reservation service + inventory ops services
8. Purchasing services (PR/RFQ/PO/3-way match) + Receiving services (GR/inspection/return)
9. Controllers + routes
10. Tests (race, rollback, konversi, moving average, 3-way match) + README

## Database Changes
Tabel §3.6–3.8 blueprint. **Catatan:** `stock_reservations.mo_id` dibuat sebagai kolom plain + index; FK ke `production_orders` ditambahkan di Phase 5 (tabel target belum ada).

## Testing (DoD — wajib hijau sebelum lanjut)
- **Race:** dua issue paralel untuk stok sama → total tidak pernah melebihi stok; tidak ada saldo negatif (CHECK menolak).
- **Atomic:** dokumen gagal di tengah ⇒ ledger & saldo tidak berubah sama sekali (rollback total).
- **Konversi:** roll 100 kg, GSM 180, lebar 150 cm → meter tersimpan ± toleransi (BR-002).
- **Moving average:** dua GR harga berbeda → avg_cost benar; return mengoreksi benar.
- **Quality hold:** GR masuk QUALITY_HOLD; tidak available sebelum inspeksi PASS; release memindahkan saldo (BR-004).
- **3-way match:** invoice match → MATCHED; harga melebihi toleransi → MISMATCH (BR-050).
- **Available:** on_hand − reserved − quality_hold selalu benar setelah serangkaian transaksi (BR-006).

## Risks
| Risiko | Mitigasi |
|---|---|
| Stok negatif / selisih permanen | CHECK ≥ 0 + lock + ITS satu pintu + test race |
| Moving average drift saat return/adjustment | Test khusus return/adjustment; ledger sebagai kebenaran untuk rekalkulasi |
| Performa ledger besar | Append-only + index by (company, material, warehouse, created_at); summary di balances |

## Open Decisions (default dipakai, keputusan resmi paling lambat akhir fase)
- OBD-006 (shade rule saat alokasi roll → default: validasi configurable per buyer)
- OBD-014 (backflush trim murah — default: aktual untuk fabric, backflush ⚙️ per material class)

## Next Step
Batch 2: migrasi inventory engine. Setelah fase hijau: review → Phase 5 (MRP, Planning, MO).
