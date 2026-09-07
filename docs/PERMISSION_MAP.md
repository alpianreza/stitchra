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

### Iteration 28 Product Development endpoints

Pemetaan granular berikut memperjelas wildcard historis di atas. Semua route tetap membutuhkan authentication dan company scope; tidak ada permission/role baru.

| Endpoint | Permission |
|---|---|
| GET `/api/pd/styles` | Salah satu `pd.style.view`, `pd.sample.view`, `pd.sample.create`, `pd.techpack.view`; lookup metadata saja |
| GET `/api/pd/sizes`, `/api/pd/styles/{style}/development`, `/api/pd/measurement-charts/{measurementChart}` | `pd.style.view`; daftar Tech Pack dalam workbench juga memerlukan `pd.techpack.view` |
| POST `/api/pd/styles/{style}/specs`, `/api/pd/styles/{style}/measurements` | `pd.style.update`; membuat versi baru pada style existing |
| POST `/api/pd/styles/{style}/tech-packs` | `pd.techpack.create` |
| GET `/api/pd/tech-packs/{techPack}/download` | `pd.techpack.view`; file privat, tenant-scoped |
| GET `/api/pd/styles/{style}/sample-sources` | `pd.sample.create`; metadata versi, bukan download file |
| GET `/api/pd/samples`, `/api/pd/samples/{sample}` | `pd.sample.view` |
| POST `/api/pd/samples` | `pd.sample.create` |
| POST `/api/pd/samples/{sample}/approvals` | `pd.sample.submit`; mencatat respons buyer, bukan approval internal baru |
| GET `/api/production/orders/{productionOrder}/sample-gate` | `production.mo.view`; lookup sample terbatas pada style/company MO |
| POST `/api/production/orders/{productionOrder}/sample` | `production.mo.update`; hanya MO PLANNED |
| POST `/api/production/orders/{productionOrder}/release` | Existing `production.mo.release`; sekarang juga memvalidasi sample terpilih dan respons APPROVED terbaru |

Detail implementasi dan keputusan yang belum ditetapkan: [Iteration 28](./ITERATION_28_PRODUCT_DEVELOPMENT_COMPLETENESS.md). Test/runtime belum dijalankan atas permintaan user.

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

### Iteration 27 operational endpoints

Mapping implementasi ke kode permission **existing**; tidak menambah role atau menetapkan approval matrix bisnis baru.

| Endpoint | Permission |
|---|---|
| GET `/api/purchasing/rfqs`, `/api/purchasing/rfqs/{rfq}`, `/api/purchasing/sourcing/options` | `purchasing.rfq.view` |
| POST `/api/purchasing/rfqs` | `purchasing.rfq.create` |
| POST `/api/purchasing/rfqs/{rfq}/quotations`, `/api/purchasing/rfqs/{rfq}/close` | `purchasing.rfq.update` |
| POST `/api/purchasing/rfqs/{rfq}/quotations/{quotationId}/award` | **Keduanya:** `purchasing.rfq.update` + `purchasing.po.create`; hasil tetap PO DRAFT |
| GET `/api/receiving/purchase-orders`, `/api/receiving/purchase-orders/{purchaseOrder}`, `/api/receiving/warehouses`, `/api/receiving/locations` | `receiving.gr.create`; PO lookup tidak mengekspos harga |
| GET `/api/receiving/inspection-grs`, `/api/receiving/inspection-grs/{goodsReceipt}`, `/api/receiving/inspection-defects` | `receiving.inspection.view` |
| GET `/api/receiving/grs/{goodsReceipt}/stock-trace` | `receiving.gr.view` |
| POST `/api/receiving/grs/{goodsReceipt}/supplier-returns`, `/api/receiving/grs/{goodsReceipt}/putaways` | `receiving.gr.create` |
| POST `/api/receiving/supplier-returns/{supplierReturn}/post`, `/api/receiving/putaways/{putaway}/post` | `receiving.gr.submit` |
| POST `/api/receiving/supplier-returns/{supplierReturn}/cancel`, `/api/receiving/putaways/{putaway}/cancel` | `receiving.gr.update`; hanya DRAFT |

Semua endpoint tetap `auth:sanctum` dan company-scoped. GR/QC response menyembunyikan harga tanpa `purchasing.po.view`; stock trace dan normalized receiving-line response tidak membuka unit cost. Hak post adalah kontrol posting operasional, bukan bukti maker/checker approval. Detail scope dan batas keputusan: [Iteration 27](./ITERATION_27_PURCHASING_RECEIVING_COMPLETENESS.md).

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
