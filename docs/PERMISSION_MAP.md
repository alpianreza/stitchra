# Permission Map — Endpoint → Permission Code (Canonical, LOCKED blueprint v1.1)

> Sumber kebenaran: `database/seeders/RbacSeeder.php` (16 role + kode `domain.entity.action` dari blueprint LOCKED).
> Controller WAJIB memakai kode di sini. Temuan mismatch awal (controller vs seeder) sudah diperbaiki — commit penyelarasan Phase RBAC.

## Core
| Endpoint | Permission |
|---|---|
| POST `/api/auth/login` (public), logout, me | — (auth) |
| GET `/api/approvals/pending`, approve/reject/revision | role-based step match (ApprovalEngine `assertCanAct` + `core.approval.manage` override) |
| GET/POST `/api/approvals/flows`, roles, deactivate | `core.approval.manage` (super_admin fallback) |

## Master Data (`/api/master/{entity}`)
`master.<entity>.<view|create|update|delete>` — entity: customer, supplier, employee, style (juga colors/colorways/shade-groups/sizes/size-ranges), material, uom, warehouse, line, machine, operation, defect (defect-library), finance (COA, currencies, exchange-rates, overhead-rates, line-cost-rates). Import: `master.<entity>.create`.

## Sales & PD
| Endpoint | Permission |
|---|---|
| `/api/sales/orders` (GET/POST) | `sales.order.view` / `sales.order.create` |
| submit / confirm | `sales.order.submit` / `sales.order.approve` |
| `/api/pd/boms*` | `pd.bom.view/create/update/submit` |
| `/api/pd/routings*` | `pd.routing.view/create/submit` |
| `/api/pd/cost-sheets*` | `pd.costing.view/create/update/submit` |
| `/api/pd/samples*` | `pd.sample.create/update` |

## Planning & Production
| Endpoint | Permission |
|---|---|
| `/api/planning/mrp-runs` GET / POST | `planning.mrp.view` / `planning.mrp.execute` |
| convert-to-pr | `purchasing.pr.create` |
| `/api/production/orders` GET / from-so | `production.mo.view` / `production.mo.create` |
| release / unrelease | `production.mo.release` |
| issues POST / backflush | `production.issue.execute` |
| issues GET | `production.issue.view` |
| return leftover | `cutting.leftover.execute` |

## Shop Floor & Cutting
| Endpoint | Permission |
|---|---|
| `/api/shopfloor/scans` POST | `production.output.create` |
| `/api/shopfloor/wip/*`, daily-output | `production.output.view` |
| `/api/cutting/orders*` | `cutting.order.execute` |
| markers | `cutting.marker.execute` |
| bundles | `cutting.bundle.execute` |

## Purchasing & Receiving
| Endpoint | Permission |
|---|---|
| `/api/purchasing/prs*` | `purchasing.pr.view/create/submit` |
| `/api/purchasing/pos*` | `purchasing.po.view/create/submit` |
| `/api/purchasing/invoices` POST | `purchasing.invoice.create` |
| `/api/purchasing/invoices/{id}/match` | `purchasing.invoice.approve` |
| `/api/receiving/grs*` | `receiving.gr.view/create` |
| `/api/receiving/*/inspections` POST / finalize | `receiving.inspection.create` / `receiving.inspection.update` |

## QC / Packing / Shipping / Subcon
| Endpoint | Permission |
|---|---|
| `/api/qc/*` | `quality.inspection.view/create/update/submit` (finalize = submit) |
| `/api/packing/lists*` | `packing.packinglist.view/create/update/submit` (finalize = submit) |
| `/api/shipping/shipments` GET/POST | `shipping.shipment.view/create` |
| approve-over-tolerance | `shipping.shipment.update` |
| ship | `shipping.shipment.submit` |
| `/api/subcon/orders` GET/POST | `subcon.jwo.view/create` |
| subcon receive | `subcon.movement.create` |

## Finance & Costing
| Endpoint | Permission |
|---|---|
| `/api/finance/journals` POST | `finance.journal.create` |
| journal reverse | `finance.journal.approve` |
| trial-balance | `finance.report.view` |
| periods/close | `finance.period-closing.execute` |
| account-mappings | `master.finance.manage` |
| AR invoice create / aging | `finance.ar-invoice.create` / `finance.ar-invoice.view` |
| AP/AR payments | `finance.payment.create` |
| AP aging | `finance.ap.view` |
| costing actual | `costing.actual.view` |
| BEP | `finance.bep.view` |

## Reporting & Dashboard
| Endpoint | Permission |
|---|---|
| `/api/reporting/reports*` | salah satu `reporting.{sales,ppic,inventory,purchasing,quality,finance,traceability}.view` |
| `/api/dashboard/kpis` | salah satu `dashboard.{management,ppic,warehouse,production,qc}.view` |

super_admin mendapatkan semua permission (`*`).
