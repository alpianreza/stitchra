# Modul Master Data

Seluruh master data bisnis menjadi fondasi MRP, costing, inventory, dan transaksi.

## Entitas

Registry: `Support/MasterDataRegistry.php`.

Customer (+AQL config), Supplier, Employee, Style, Color, Colorway, Shade Group, Size, Size Range, UOM, Material, Warehouse, Line, Machine, Operation, Defect Library, COA, Currency, Exchange Rate, Overhead Rate, dan Line Cost Rate.

## Endpoint

| Method | Path | Permission |
|---|---|---|
| GET | `/api/master/{entity}` | `master.<entity>.view` |
| POST | `/api/master/{entity}` | `master.<entity>.create` |
| GET | `/api/master/{entity}/{id}` | `master.<entity>.view` |
| PUT | `/api/master/{entity}/{id}` | `master.<entity>.update` |
| DELETE | `/api/master/{entity}/{id}` | `master.<entity>.delete` |
| POST | `/api/master/{entity}/import` | `master.<entity>.create` |

Query list: `?q=`, `?active=1`, dan `?per_page=1..100`. Search hanya memakai kolom yang tersedia pada registry entity.

## Validation and tenant rules

- Semua foreign-key `exists` pada CRUD dan import dibatasi ke company aktif.
- Kode, style number, dan NIK unik per company.
- Composite unique divalidasi untuk colorway, exchange rate, overhead rate, dan line cost rate.
- Country, currency, category/type, tracking level, lifecycle, section, severity, dan normal balance dinormalisasi ke uppercase.
- BR-003: material `FABRIC` wajib `ROLL`; `TRIM` dan `PACKAGING` wajib `LOT`.
- GSM, lebar, exchange rate, overhead rate, dan line cost rate yang diisi harus lebih besar dari nol.
- Master kritis yang sudah direferensikan transaksi tidak dapat dihapus (`409 Conflict`). Master yang belum dipakai tetap menggunakan soft delete bila model mendukungnya.

## CSV import

- Maksimum file upload: 10 MB.
- Maksimum baris: `MASTER_IMPORT_MAX_ROWS` (default 10.000).
- Header kosong, duplikat, atau tidak dikenal ditolak.
- Validasi baris menggunakan rule yang sama dengan CRUD, termasuk tenant-scoped references dan unique constraints.
- Error database tidak dikembalikan mentah ke client.
- File CSV dibaca dengan konfigurasi escape eksplisit agar kompatibel dengan PHP 8.5.

## Rule terkait

BR-002, BR-003, BR-008, BR-021, BR-023, BR-033, BR-053, BR-072, BR-090, BR-101, BR-102, BR-110, dan BR-112.

## Verification status

Regression tests tersedia untuk CRUD/permission, pencarian aman, tenant-scoped references, composite unique, material tracking, deletion guard, dan import CSV. Hasil test belum dinyatakan hijau sampai dependency lockfiles tersedia dan CI berjalan dari clean checkout.
