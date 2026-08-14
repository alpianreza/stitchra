# Modul Master Data

Seluruh master data bisnis (22 entitas) — fondasi untuk MRP, costing, dan transaksi.

## Entitas (registry: `Support/MasterDataRegistry.php`)
Customer(+AQL cfg), Supplier (FABRIC/TRIM/PACKAGING/SUBCON), Employee, Style, Color, Colorway, ShadeGroup, Size, SizeRange, UOM, Material (FABRIC/TRIM/PACKAGING, dual UOM), Warehouse (RM/WIP/FG/TRIM/SUBCON_VIRTUAL), Line, Machine, Operation (+SMV versioned), DefectLibrary, COA, Currency, ExchangeRate, OverheadRate, LineCostRate.

## Endpoint
| Method | Path | Permission |
|---|---|---|
| GET | `/api/master/{entity}` | `master.<entity>.view` |
| POST | `/api/master/{entity}` | `master.<entity>.create` |
| GET | `/api/master/{entity}/{id}` | `master.<entity>.view` |
| PUT | `/api/master/{entity}/{id}` | `master.<entity>.update` |
| DELETE | `/api/master/{entity}/{id}` | `master.<entity>.delete` (soft) |
| POST | `/api/master/{entity}/import` | `master.<entity>.create` (CSV) |

Query: `?q=` (search code/name), `?active=1`, `?per_page=` (max 100).

## Rule terkait
BR-002 (dual UOM, konversi per material → per roll saat GR), BR-003 (ROLL/LOT), BR-008 (AQL cfg), BR-021 (toleransi), BR-023 (lifecycle style), BR-033 (SMV versioned), BR-053 (shade group), BR-072 (defect library), BR-090 (SUBCON_VIRTUAL), BR-101 (COA), BR-102 (kurs).
