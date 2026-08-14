# Modul Finance & Costing

GL lengkap (jurnal balanced), AR/AP, actual costing per MO, BEP.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/finance/journals` | `finance.journal.create` | **BR-101**: wajib balanced; debit XOR credit per baris |
| POST | `/api/finance/journals/{id}/reverse` | `finance.journal.reverse` | koreksi via jurnal balik (asli VOID) |
| GET | `/api/finance/trial-balance?period=` | `finance.gl.view` | agregasi jurnal POSTED |
| POST | `/api/finance/periods/close` | `finance.period.close` | **BR-103**: CLOSED menolak posting |
| POST | `/api/finance/account-mappings` | `finance.mapping.update` | mapping event → akun untuk jurnal AUTO |
| POST | `/api/finance/ar/invoices/from-shipment/{id}` | `finance.ar.create` | dari shipment SHIPPED (BR-102); jurnal AUTO Piutang/Pendapatan |
| POST | `/api/finance/ar/invoices/{id}/payments` | `finance.ar.pay` | parsial OK; OPEN→PARTIAL→PAID |
| POST | `/api/finance/ap/invoices/{id}/payments` | `finance.ap.pay` | **BR-050**: hanya invoice MATCHED |
| GET | `/api/finance/ar/aging`, `/api/finance/ap/aging` | `finance.ar/ap.view` | bucket current/1-30/31-60/61-90/>90 |
| GET | `/api/finance/costing/mo/{id}/actual` | `finance.costing.view` | **BR-080/081**: actual vs standard variance |
| POST | `/api/finance/bep/style/{id}` | `finance.bep.view` | **BR-104**: BEP per style |
| POST | `/api/finance/bep/factory` | `finance.bep.view` | **BR-104**: BEP factory-wide per bulan |

## Aturan bisnis
- **BR-101**: jurnal hanya via `JournalService::post()` — balanced divalidasi; AUTO via `GlPostingService` (idempotent per event+dokumen; mapping wajib ada).
- **BR-103**: periode auto-create OPEN saat posting pertama; tutup eksplisit; CLOSED menolak posting.
- **BR-102**: kurs tersimpan per dokumen; jurnal dalam base currency (amount × exchange_rate).
- **BR-080/081**: actual = material (ledger × avg cost) + labor (output × SAM × cost/min) + OH (SAM × OH rate, BR-009) + subcon fee; variance per komponen vs cost sheet APPROVED (BR-100).
- **BR-104** (DEC-2026-08-14-01): BEP = Fixed Cost ÷ (harga − variable cost); per style (FOB cost sheet) & factory-wide (rata-rata cost sheets APPROVED). Domain Accounting.
