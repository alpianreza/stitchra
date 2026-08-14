# Modul Reporting & Dashboard

8 report inti + dashboard KPI — read-only di atas data yang dijaga ITS/approval/audit.

## Endpoint
| Method | Path | Permission |
|---|---|---|
| GET | `/api/reporting/reports` | `reporting.report.view` |
| GET | `/api/reporting/reports/{name}` | `reporting.report.view` |
| GET | `/api/reporting/reports/{name}/export` | `reporting.report.export` (CSV download) |
| GET | `/api/dashboard/kpis` | `reporting.dashboard.view` |

## Report registry
| Report | Isi | Rule terkait |
|---|---|---|
| `order_status` | SO lifecycle + qty + nilai | — |
| `wip_summary` | WIP per MO per stage | BR-063 |
| `production_efficiency` | output & SAM earned vs kapasitas line per hari (`?date=`) | BR-033 |
| `qc_summary` | verdict per stage + Pareto defect | BR-008/072 |
| `stock_aging` | umur & nilai stok per material/gudang | BR-005/006 |
| `consumption_variance` | BOM estimated vs actual per style | BR-031 |
| `otd` | on-time delivery (ship vs ex-factory) | BR-021 |
| `bep_position` | qty shipped vs BEP per style (`?fixed_cost_share=`) | BR-104 |

## Dashboard KPI
open_orders (count+value), mo_by_status, today_output_pcs, wip_pcs, qc_pass_rate_7d_pct, pending_my_approvals, overdue_deliveries, stock_value.

## Catatan
Semua query agregasi SQL read-only, company-scoped (BR-011). Tidak ada tabel baru.
