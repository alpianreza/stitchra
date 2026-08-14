# Modul Inventory (Inventory Engine)

Sumber kebenaran stok. Semua perubahan stok di SELURUH sistem hanya lewat `InventoryTransactionService` (ITS).

## Aturan keras
- **BR-013**: `stock_ledger` append-only; `stock_balances` materialized; dokumen + ledger + saldo dalam SATU transaksi DB (atomic).
- **BR-006**: `available = on_hand − reserved − quality_hold`; saldo tidak pernah negatif (CHECK + validasi ITS).
- **BR-005**: moving average — `avg_cost` di-update tiap penerimaan berbiaya; ledger menyimpan cost per transaksi.
- **BR-004**: penerimaan masuk `quality_hold`; `releaseQualityHold()` memindahkan ke available.
- **BR-017**: koreksi stok hanya via `adjust()` dari dokumen adjustment/opname yang APPROVED.
- **BR-001**: ownership COMPANY/BUYER pada saldo & ledger.

## Movement types
OPENING, PURCHASE_RECEIPT, PURCHASE_RETURN, QUALITY_RELEASE, TRANSFER_IN/OUT, MATERIAL_ISSUE, PRODUCTION_RETURN, PRODUCTION_RECEIPT, ADJUSTMENT, OPNAME_ADJUSTMENT, SUBCON_OUT/IN, SHIPMENT.

## API ITS (untuk modul lain — bukan HTTP)
```php
$its->post('PURCHASE_RECEIPT', $header, $lines, $user);   // atomic
$its->releaseQualityHold($companyId, $line, $qty, $user); // BR-004
$its->adjust($companyId, $line, $qtyDelta, $sourceType, $sourceId, $user); // BR-017
```
Modul lain DILARANG menulis tabel `stock_*` langsung (I-01).

## Reservation
`stock_reservations` (BR-006/060): hard reservation saat MO release (dipakai Phase 5).
