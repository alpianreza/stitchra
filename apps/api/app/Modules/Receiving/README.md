# Modul Receiving & Inward QC

## Fabric length UOM

- Fabric roll dapat memakai `MTR`/`METER` atau `YRD`/`YDS`/`YARD` sebagai UOM pemakaian material.
- Konversi standar: `1 YRD = 0.9144 MTR`.
- PO tetap disimpan dalam buy UOM; roll menyimpan buy quantity, use quantity, conversion rate, dan ekuivalen meter.
- Bila buy UOM adalah KG, GSM dan width dapat digunakan untuk menghasilkan meter lalu dikonversi ke use UOM.
- ITS, quality hold, release, issue, consumption, dan return selalu memakai `use_uom_id` roll/material.
- Unit cost stok dihitung per use UOM sehingga nilai PO tidak berubah akibat konversi.

## Invariants

GR/PO/roll dikunci dan tenant-scoped; over-receipt ditolak; fabric wajib per roll; inward QC dan supplier return mengambil dimensi stok dari server.

## QC, supplier return, dan putaway

- Immutable PURCHASE_RECEIPT menjadi authority lokasi, lot, quantity, UOM, dan cost unit penerimaan. QC finalize memakai dimensi asli, replay-safe, dan tidak me-release roll yang belum diinspeksi.
- Supplier Return: finalized QC FAIL → DRAFT → explicit post → SHIPPED. Normalized lines menunjuk receipt ledger; posted_at dan PURCHASE_RETURN membuktikan stock-out. Legacy label REJECTED_RETURNED sendiri bukan bukti barang sudah dikirim.
- Return hanya seluruh failed receipt unit/roll. Duplicate active/posted return ditolak; draft cancel tidak memindahkan stok. Return historis tanpa normalized lines dikenali dari ledger, bukan diposting ulang.
- Putaway: finalized QC PASS → DRAFT allocation → POSTED melalui pasangan ITS TRANSFER_OUT/TRANSFER_IN atomik. Warehouse tetap sama; fabric satu roll utuh, non-roll boleh partial.
- Putaway memakai moving-average cost source saat post. Alokasi draft bukan inventory reservation; posting tetap memeriksa available quantity dan reservation.
- Trace menghubungkan GR/receipt → normalized return/putaway lines → location ledger. Saldo non-roll/lot adalah pooled dimension balance, bukan klaim piece-level provenance.
- Posted reversal, partial failed-unit return, approval matrix baru, supplier credit/AP/GL settlement, dan perubahan PO gross received qty tidak ditambahkan.

## Verification status

[Iteration 27](../../../../../docs/ITERATION_27_PURCHASING_RECEIVING_COMPLETENESS.md): 28 targeted backend cases PASS; empat mocked-API UI flow dan tiga real-API/MySQL UI flow PASS. Clean migration chain, rollback/reapply migration 000044, TypeScript, dan Next build PASS. Full Pest tetap 42 baseline failures; production-data upgrade, true concurrency, full Playwright, serta UAT belum dinyatakan lulus. Production tetap NO-GO.
