# Modul Sales

Sales Order end-to-end: Buyer PO → SO matrix → approval → confirm → MRP.

## Endpoint

| Method | Path | Permission | Rule |
|---|---|---|---|
| GET | `/api/sales/orders` | `sales.order.view` | status tervalidasi, pagination 1–100 |
| POST | `/api/sales/orders` | `sales.order.create` | matrix lines wajib (BR-020) |
| GET | `/api/sales/orders/{id}` | `sales.order.view` | tenant-scoped detail + lines |
| POST | `/api/sales/orders/{id}/submit` | `sales.order.submit` | approval atomik (BR-015) |
| POST | `/api/sales/orders/{id}/confirm` | `sales.order.approve` | BR-023: semua style wajib BOM+Routing APPROVED |

## Invariants

- Nomor SO diterbitkan oleh `NumberingService` secara concurrency-safe (BR-010).
- Customer, currency, style, colorway, dan size harus berasal dari company aktif.
- Colorway harus benar-benar milik style pada line yang sama.
- Kombinasi style×colorway×size tidak boleh duplikat dalam satu SO; service dan unique index sama-sama menjaga aturan ini.
- Creator harus memiliki akses ke company SO.
- Submit mengunci SO dan mengubah status dalam transaksi yang sama dengan pembuatan approval request. Bila approval gagal, status kembali `DRAFT`.
- Confirm mengunci SO dan hanya menerima status terbaru `APPROVED`.
- Domain violation dari endpoint create/submit/confirm dikembalikan sebagai `422`, bukan error server.

## Lifecycle

`DRAFT → SUBMITTED → APPROVED → CONFIRMED → IN_PROGRESS → CLOSED`, dengan terminal alternatif `REJECTED` dan `CANCELLED`.

`SalesOrderService::cuttingStarted()` membaca `production_orders`; amendment harus dikunci mulai status `CUTTING` dan sesudahnya (BR-022).

## Event

Approval `SO` yang selesai memanggil `SalesOrderService::markApproved()` melalui listener `HandleDocumentApproved`.

## Verification status

Regression tests tersedia untuk tenant/matrix mismatch, approval rollback, dan BR-023 gate. Hasil belum dinyatakan hijau sampai lockfile tersedia dan CI dijalankan dari clean checkout.
