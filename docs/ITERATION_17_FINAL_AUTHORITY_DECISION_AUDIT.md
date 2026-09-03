# STITCHRA — ITERATION 17: FINAL AUDIT & DECISION CLOSURE

> **Type:** Documentation and authority audit only  
> **Result:** AUDIT COMPLETE — DECISIONS PENDING  
> **Audit date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `41613086fda9ce90c129cea9d04f28b39a5c8dce`  
> **Runtime:** DEFERRED — FINAL VERIFICATION PHASE  
> **Rule:** Existing implementation is evidence, not automatically a business rule.

## 1. Baseline verification

- Branch read: `main`.
- Starting HEAD confirmed: `41613086fda9ce90c129cea9d04f28b39a5c8dce`.
- Latest schema file present: `2026_09_02_000027_harden_subcon_traceability.php`.
- Iteration 17 adds no migration, schema change, writer, endpoint, business rule, backfill, or historical rewrite.
- Applied database migration state was not executed or inspected at runtime.
- Feature/regression tests from Iterations 1–16 are prepared but were not run in this iteration.
- PHP/Pest, migration execution, API runtime, Next build, Playwright, accounting, security, and concurrency verification remain deferred.
- Iteration 15 boundary: new mixed Marker/Lay consumption is blocked; historical mixed evidence remains readable; no automatic reconciliation.
- Iteration 16 boundary: ITS and GL idempotency/active-company controls were hardened; unresolved production and valuation authorities were not selected.

### Documents inspected

- `docs/ERP_GARMENT_BUSINESS_RULES.md`
- `docs/ERP_GARMENT_PROCESS_FLOW.md`
- `docs/ERP_GARMENT_DATABASE_BLUEPRINT.md`
- `docs/ERP_GARMENT_IMPLEMENTATION_ROADMAP.md`
- `docs/DECISION_LOG.md`
- `docs/DECISION_LOG_OPEN_2026-09-02_CUTTING_CONSUMPTION_AUTHORITY.md`
- `docs/00-governance/PROJECT_STATUS.md`
- `apps/api/app/Modules/Production/README.md`
- Relevant migrations, services, routes, and read-only authority services at the baseline HEAD.

### Existing decisions already authoritative

The following are already defined and are not reopened by this audit:

- `INVENTORY_AUTHORITY = ITS` under BR-013.
- Inventory valuation method is Moving Average under BR-005, for transactions that possess a defined/stored cost.
- Fabric is roll-tracked and Material Issue is reservation-controlled.
- New Packing carton/finalization flow is gated by QC FINAL PASS under BR-080.
- Approved cost sheet is the standard-cost source under BR-100.
- Internal GL, balanced journals, period control, and journal reversal exist under BR-101/103.
- BR-053 shade compatibility is separately locked by OBD-006.

These rules do not by themselves select fabric-consumption authority, whole-MO output, WIP/FG/Shipment valuation, cost-per-unit denominator, COGS amount, or operational reversal policy.

## 2. Executive authority matrix

| ID | Authority | Current state | Existing evidence | Candidate options | Business impact | Technical impact | Decision |
|---|---|---|---|---|---|---|---|
| D-01 | Actual Fabric Consumption | DECISION REQUIRED | Marker and Lay Roll both consume dispatch/physical Roll and update MO actual consumption differently; BR-031 points to Marker, while BR-041/PF-05 point to Lay | MARKER / LAY_ROLL / OTHER or remain undefined | Determines actual fabric usage, wastage, costing, and cutting completion | Cutting services, dispatch controls, MO allocations, legacy path, tests; migration depends on chosen provenance policy | PENDING |
| D-02 | Historical Marker/Lay Mixed Path | DECISION REQUIRED | Historical coexistence is readable; new completion is blocked; BR-017 prohibits direct historical edits and requires approved adjustment | Frozen read-only / metadata reconciliation / approved adjustment / controlled quantity correction | Determines whether old MO can close and how audited balances are accepted | Reconciliation document/approval/audit may be required; no automatic backfill | PENDING |
| D-03 | Whole-MO Production Output | NOT DEFINED | Stage authorities exist, but `qty_produced` has no inspected operational writer and is non-authoritative | Cut / Sewing / Finishing / QC accepted / Packing or FG / separate physical and accepted measures | Determines MO completion, progress, output, backflush, costing denominator | Production lifecycle, scans, QC, Packing, reporting, APIs, UI; persistence may require schema | PENDING |
| D-04 | ACTUAL vs BACKFLUSH | DECISION REQUIRED | Both create Material Issue and ITS `MATERIAL_ISSUE`; Backflush subtracts prior BACKFLUSH only and reads legacy `qty_produced` | Mutually exclusive / Backflush provisional then delta / ACTUAL replaces Backflush / class-specific authority | Determines inventory issue and material actual cost without duplication | MaterialIssueService, BOM flags, reservations, ITS source documents, costing, tests | PENDING |
| D-05 | Legacy Packing Source | PARTIAL / DECISION REQUIRED | `qc_inspection_id` is nullable for history; new cartons/finalization require QC FINAL PASS; missing source may be attached during mutation | Permanent read-only / allow controlled source attachment / allow eligible finalize / prohibit legacy mutation | Determines whether historical DRAFT rows can progress | Packing service/controller, audit, UI, compatibility tests; existing column may suffice | PENDING |
| D-06 | WIP Valuation | NOT DEFINED | Material Issue may carry cost; scans/WIP transfers carry quantity only; Actual Cost is computed read-only | Material-only accumulation / standard stage value / actual accumulated value / hybrid / remain undefined | Determines WIP asset and production accounting | Inventory/Production/Finance, valuation layers, GL mappings, reversal, period tests | PENDING |
| D-07 | FG Valuation | NOT DEFINED | Packing posts ITS `PRODUCTION_RECEIPT` without authoritative unit cost; standard and computed actual cost exist but neither is selected | Standard / actual / provisional standard plus variance / hybrid / remain undefined | Determines FG carrying value and downstream COGS | Packing, ITS, Actual Cost, GL posting, receipt valuation, tests; likely persistence/timing decision | PENDING |
| D-08 | Shipment Valuation | NOT DEFINED | ITS `SHIPMENT` posts quantity; shipment ledger does not receive an authoritative cost source | Consume FG layer cost / standard / actual or reconciled / remain undefined | Determines value removed from FG | Shipment, ITS ledger valuation, Finance posting, reversal and period handling | PENDING |
| D-09 | Cost per PCS | NOT DEFINED | Actual Cost total is computed read-only; `per_pcs` is deliberately null | Planned / cut / sewn / finished / QC accepted / packed / FG received / shipped / multiple named KPIs | Determines unit cost, variance, margin, and BEP input | Costing API/UI/reporting, denominator snapshots, partial-output rules, tests | PENDING |
| D-10 | COGS | NOT DEFINED / BLOCKED | BR-083/PF-10 identify Shipment as boundary, but code has no authoritative shipment cost or `SHIPMENT_COGS` posting | Shipment-time posting from FG cost / other approved timing / remain blocked | Determines income statement and inventory relief | OperationalPostingService, account mappings, journals, Shipment reversal, period rules | PENDING |
| D-11 | Reversal / Cancellation / Timing | PARTIAL / DECISION REQUIRED | Journal reversal exists; generic ITS reversal and domain reversal/cancel flows are incomplete; closed periods reject posting | Domain reversal documents / approved adjustments / cancellation-before-downstream plus reversal-after-posting | Determines correction, period integrity, audit, and historical closure | Cross-module lifecycles, reversal links, APIs, permissions, audit and concurrency tests | PENDING |

No row is marked LOCKED by Iteration 17.

## 3. Detailed authority audit

### D-01 — Actual Fabric Consumption

**Status:** DECISION REQUIRED.

#### Shared implementation evidence

- `MaterialIssueService::issue()` creates ACTUAL Material Issue, consumes reservation through ITS `MATERIAL_ISSUE`, and increases `fabric_dispatch_balances.qty_dispatched` for a Roll.
- `CuttingService::recordMarker()` writes `marker_logs`, increases dispatch `qty_consumed`, and reduces physical Fabric Roll remaining.
- `LayExecutionService::addRollInternal()` writes `lay_rolls`, increases the same dispatch `qty_consumed`, and reduces the same physical Roll remaining.
- `CuttingService::complete()` adds Marker usage to `mo_material_allocations.qty_consumed`.
- `LayExecutionService::completeLay()` sets MO allocation consumption from aggregate Lay Rolls and derives `actual_consumption_per_pcs` from Cut Outputs.
- Iteration 15 blocks new mixed execution in both directions and blocks completion of historical mixed evidence.
- Marker/Lay consumption changes are operational dispatch/physical-Roll controls, not a second ITS stock movement. The Material Issue remains the ITS inventory outflow.
- Locked BR-031 says actual consumption comes from Marker realization and leftover return.
- Default BR-041 says actual fabric issue is per Roll from Lay; PF-05 calculates leftover from Lay usage while Marker stores marker length/efficiency.
- No binding decision resolves the conflict.

#### Candidate A — MARKER

- **Evidence:** BR-031 explicitly names Marker realization; legacy Marker writes dispatch and Roll usage and Marker completion updates MO actual consumption.
- **Operational implication:** Marker remains both efficiency and actual-consumption transaction; Lay Roll must stop being a competing consumption writer or become derived allocation evidence.
- **Inventory implication:** Material Issue remains ITS inventory outflow; Marker governs used-versus-leftover split within issued Roll quantity.
- **Traceability implication:** Marker must retain/obtain deterministic lineage through Cut Order to resulting Cut Output/Bundle or be explicitly accepted as a legacy actual path.
- **Historical compatibility:** Marker history remains directly compatible; Lay-only history requires an approved policy, not assumed conversion.
- **Risks:** PF-05 Lay usage evidence may diverge from Marker; shade-aware Roll allocation could become operational but non-authoritative; current dual writer must be removed or constrained.
- **Migration impact:** Not automatically required. A migration is required only if the approved design needs persisted authority/provenance or links absent from existing schema.
- **Implementation if selected:** lock decision; define Lay Roll role; make completion idempotent; enforce one path; update MO allocation synchronization, leftover/wastage, lineage, UI, tests, and historical policy.

#### Candidate B — LAY_ROLL

- **Evidence:** BR-041 and PF-05 describe actual Roll usage from Lay; Lay Roll carries exact Roll, use-UOM, quantity, shade control, Cut Output and Bundle lineage.
- **Operational implication:** Marker becomes planning/efficiency evidence or a compatibility-only path that cannot write actual consumption for new transactions.
- **Inventory implication:** Material Issue remains ITS inventory outflow; Lay Roll governs used-versus-leftover split and MO actual consumption.
- **Traceability implication:** Strong forward and reverse chain exists: Fabric Roll → Lay Roll → Lay → Cut Output → Bundle.
- **Historical compatibility:** Legacy Marker-only records remain readable; historical conversion cannot be inferred without explicit policy.
- **Risks:** BR-031 must be formally amended or clarified; Marker completion behavior must be changed only after approval; existing Marker-derived actual reports may change.
- **Migration impact:** Not automatically required. Persisted authority/version metadata may require additive schema if approved.
- **Implementation if selected:** lock decision; classify Marker as efficiency/compatibility; enforce Lay Roll as sole new writer; revise completion/allocation/wastage behavior; preserve legacy reads; add tests and migration only if the decision requires persisted classification.

#### Candidate C — OTHER / UNDEFINED

- **Evidence:** No third persisted transaction currently resolves both requirements. Material Issue proves issued quantity, not consumed-versus-returned cutting usage.
- **Operational implication:** Existing new mixed guard remains; each single path can retain compatibility behavior, but cross-path completion and authoritative actual consumption remain blocked.
- **Inventory implication:** ITS inventory issue and dispatch limits remain operational, but cutting actual allocation and actual consumption reports remain non-final.
- **Traceability implication:** Path-specific traces remain truthful; there is no unified consumption authority.
- **Historical compatibility:** Safest for non-rewrite, but old/new records remain semantically different.
- **Risks:** Actual costing, wastage, completion and reconciliation cannot be finalized.
- **Migration impact:** None while kept undefined.
- **Implementation if selected:** no feature implementation; preserve blocks and expose decision state.

### D-02 — Historical Marker/Lay Mixed Data

**Status:** DECISION REQUIRED.

**Current behavior:** mixed historical evidence is readable, no rows are rewritten, and Cut Order/Lay completion mutation is blocked with a conflict. New mixed execution is rejected under shared locks.

**Questions not answered by current authority:** whether coexistence is accepted as valid history; whether an MO may complete; who may reconcile; whether reconciliation changes quantity, creates an approved inventory/production adjustment, or adds metadata only.

**Options:**

1. **Frozen historical evidence:** permanently readable, never operationally completed or changed.
2. **Metadata-only resolution:** preserve all quantities and attach an approved explanation/classification.
3. **Approved adjustment:** preserve source rows and post a separately authorized correction under BR-017/BR-013.
4. **Controlled quantity correction:** only if a future decision explicitly defines source precedence and legal/audit requirements; never direct silent rewrite.

**Evidence-based recommendation:** preserve original Marker/Lay/ledger rows; keep completion blocked until an explicit policy exists; if correction is approved, prefer a separately traceable, approved adjustment over direct edits. This follows BR-013, BR-016 and BR-017 but does not decide the reconciliation amount, approver, or accounting effect.

### D-03 — Whole-MO Production Output

**Status:** NOT DEFINED / DECISION REQUIRED.

- `production_orders.qty_produced` has default zero and remains fillable compatibility data, but no operational writer was found in inspected Production/Cutting/ShopFloor/QC/Packing services.
- Cut Output is defined cutting quantity and sources new Bundles.
- Bundle is derived and may include historical rows without Cut Output.
- Final routing OUT scan is defined operation-level output and currently feeds labor/OH evidence, but this implementation choice is not a locked whole-MO rule.
- Finishing OUT exists, but mandatory terminal Finishing operation/completion marker is not defined.
- QC FINAL PASS is accepted output for Packing eligibility under BR-080.
- Packing/Carton is downstream packed quantity.
- ITS `PRODUCTION_RECEIPT` is authoritative FG quantity by Packing List, not automatically whole-MO physical production output.
- Shipment is commercially fulfilled quantity, not production output.

The decision must explicitly separate:

- **Physical production output:** candidate stage such as terminal Sewing/Finishing evidence.
- **Accepted production output:** candidate QC FINAL PASS quantity.
- **Commercially fulfilled output:** Packing, FG receipt or Shipment quantity.

Possible outcomes include selecting one named whole-MO measure or retaining separate named measures and defining which one drives completion, backflush, costing and reports. No candidate is selected here.

### D-04 — ACTUAL vs BACKFLUSH

**Status:** DECISION REQUIRED.

- ACTUAL Material Issue is reservation- and Roll/lot-controlled and posts ITS `MATERIAL_ISSUE`.
- BACKFLUSH uses BOM lines flagged `is_backflush`, computes target from `grossPerPcs × qty_produced`, consumes reservation and posts the same ITS movement type.
- Backflush subtracts only prior BACKFLUSH issue quantity. It does not subtract ACTUAL issues for the same material.
- Actual Cost reads valued Material Issue rows, including both modes, then subtracts valued Production Return.
- BR-041 says actual fabric per Roll and allows cheap trims to backflush by output × BOM, configurable by material class. It does not define overlap, precedence, delta, replacement, or under/over correction.
- `qty_produced`, the current Backflush driver, is non-authoritative.

Candidate semantics requiring approval:

1. ACTUAL and BACKFLUSH mutually exclusive per material/MO.
2. Backflush is provisional and later reconciled by delta to ACTUAL.
3. ACTUAL replaces/reverses prior Backflush.
4. Class-specific final authority: fabric ACTUAL, approved trim classes BACKFLUSH.
5. Keep Backflush compatibility-only and blocked from authoritative use.

Until decided, behavior for actual below/above Backflush, inventory correction, costing precedence and reversal remains blocked.

### D-05 — Legacy Packing Output Dependency

**Status:** PARTIAL / DECISION REQUIRED.

- Original `packing_lists.production_order_id` is nullable.
- Additive migration `2026_09_02_000026` adds nullable `qc_inspection_id` specifically to preserve historical rows.
- `PackingService::lineage()` exposes missing source as `MISSING_LEGACY_SOURCE`; historical rows remain readable.
- Packing List creation requires a selected MO but can create a DRAFT shell with null QC source when MO is not yet at QC.
- Adding a Carton and finalizing require an eligible QC FINAL PASS. If a DRAFT Packing List has a valid MO and null `qc_inspection_id`, `assertPackingInput()` may attach the latest eligible PASS and audit that mutation.
- Finalization still uses legacy `qty_produced` as an extra ceiling and PACKED transition trigger; this is compatibility behavior, not output authority.
- No Bundle or Finishing Output foreign key exists on Carton lines.
- No Packing cancellation/edit/source-attachment-specific route is exposed.

Decision options:

1. Historical missing-source rows are permanently read-only.
2. DRAFT legacy rows may attach a verified same-company/same-MO QC FINAL PASS and continue.
3. Attachment requires a dedicated approval/manual action instead of automatic attachment during Carton/finalize.
4. All legacy mutation remains blocked; only new source-complete Packing is operational.

BR-080 locks the Carton eligibility boundary, but it does not by itself settle creation timing, legacy editability, attachment authority, or the `qty_produced` compatibility dependency.

### D-06 — WIP Valuation

**Status:** NOT DEFINED.

- ITS Material Issue may carry Moving Average unit cost and removes RM.
- Production scans and `wip_transfers` are append-only quantity/lineage evidence and create no ITS WIP valuation movement.
- Labor and overhead are computed from output × SAM × configured rates in the read-only Actual Cost view.
- Subcontract cost is sourced from linked fees.
- No rule allocates material, labor, overhead or subcon cost to WIP stages or defines recognition timing.
- `MATERIAL_ISSUE` account mapping may exist, but `ValuationBoundaryService` and `OperationalPostingService` deliberately block WIP posting.

Candidates include material-only WIP, standard stage valuation, accumulated actual cost, or provisional standard plus variance. These are accounting policies and remain unselected.

### D-07 — FG Valuation

**Status:** NOT DEFINED.

- Packing finalization posts ITS `PRODUCTION_RECEIPT` quantity without an authoritative `unit_cost`.
- BR-005 defines Moving Average mechanics but cannot supply a missing source cost.
- BR-100 defines the approved standard-cost source.
- BR-009/PF-11 define actual MO cost components, but the implemented Actual Cost remains computed read-only, may be partial, and has no approved per-unit denominator.
- No rule selects standard, actual, hybrid, provisional value, variance timing, or recalculation policy for FG receipt.
- `PRODUCTION_RECEIPT` mapping may exist, but posting is blocked.

Candidate methods: standard, actual, hybrid/provisional standard with later variance, or remain undefined. No method is selected.

### D-08 — Shipment Valuation

**Status:** NOT DEFINED.

- Shipment posts ITS `SHIPMENT` quantity from the exact eligible Packing/FG matrix.
- The Shipment ledger has no authoritative cost if FG receipt has no valuation source.
- No implementation selects FG layer cost, standard cost, actual cost, or reconciled cost for Shipment.
- Operational shipping remains functional; accounting valuation remains blocked.

### D-09 — Cost per PCS

**Status:** NOT DEFINED.

Potential denominators have different meanings:

- SO/MO planned quantity: plan, not realized output.
- Cut Output: cutting production.
- Bundle: derived cut quantity.
- Final Sewing OUT: operation output.
- Finishing OUT: finishing evidence without locked terminal definition.
- QC FINAL PASS: accepted quantity.
- Packing/FG receipt: packed/stocked quantity.
- Shipment: commercially fulfilled quantity.
- `qty_produced`: legacy non-authoritative fallback.

Actual Cost deliberately returns `per_pcs = null`. A decision must name the denominator, treatment of partial lots/rework/reject/scrap, snapshot timing, and whether multiple named unit-cost KPIs are required.

### D-10 — COGS

**Status:** NOT DEFINED / BLOCKED.

- BR-083 and PF-09/PF-10 establish Shipment/DEPARTED as the business boundary for FG reduction and COGS basis.
- Current implementation uses `SHIPPED`, not the blueprint lifecycle value `DEPARTED`.
- ITS `SHIPMENT` reduces FG quantity.
- `SHIPMENT_COGS` is an allowed/mappable accounting event name, but no posting is allowed because Shipment valuation/COGS amount is undefined.
- No COGS journal writer, authoritative amount, posting timing policy, shipment accounting reversal, or late/closed-period treatment is implemented.
- A configured mapping alone is not authority to post.

A decision must align operational trigger terminology/timing, valuation source, partial shipment behavior, period date, and reversal before COGS implementation.

### D-11 — Reversal / Cancellation / Timing

**Status:** PARTIAL / DECISION REQUIRED.

| Area | Existing behavior | Authority state |
|---|---|---|
| ITS | Append-only ledger; no generic `reverse()`; corrections can be new opposite movement/approved adjustment | General domain reversal mapping NOT DEFINED |
| GR | No inspected GR reversal endpoint tied to ITS and GL reversal | NOT DEFINED |
| Journal | Explicit 1:1 reversal exists; original becomes VOID; target period must be OPEN | DEFINED for journals only |
| Shipment | Schema allows CANCELLED, but no cancellation/reversal route or service was found; SHIPPED posts ITS outflow | NOT DEFINED after posting |
| Packing | Schema allows CANCELLED, but no cancellation route/service was found; APPROVED posts FG receipt | NOT DEFINED after receipt |
| Production | `unrelease` exists only for RELEASED MO with no issued reservation quantity; no general production reversal/completion authority | PARTIAL |
| QC | Verdict is immutable in current service; new cycle only follows REWORK; no verdict reversal route | NOT DEFINED |
| NCR | Approval reject/close lifecycle exists; no approved-NCR reversal | NOT DEFINED |
| Rework | `resolve` closes work evidence and restores Bundle when no open record; it is not a transactional reversal | NOT DEFINED |
| Closed period | Journal posting is rejected; BR-103 says correction via current-period adjustment | Accounting boundary PARTIAL; operational-to-accounting timing NOT DEFINED |

BR-012/017 provide general principles—no cancel after downstream documents, use reversal/return, and approved adjustment for historical correction—but each domain still needs source document, opposite movement, permissions, date/period, idempotency and downstream-effect decisions.

## 4. Authority dependency graph

```text
D-01 Fabric Consumption
        ↓
D-03 Whole-MO Production Output
        ↓
D-04 ACTUAL vs BACKFLUSH
        ↓
D-06 WIP Valuation
        ↓
D-07 FG Valuation
        ↓
D-08 Shipment Valuation
        ↓
D-10 COGS
        ↓
D-11 Reversal / Cancellation / Timing
```

Cross-cutting dependency:

```text
D-03 Production Output ──→ D-09 Cost per PCS
D-06/D-07 valuation ─────→ D-09 Cost per PCS and D-10 COGS
```

Independent but compatibility-critical:

```text
D-02 Historical Marker/Lay Mixed Data
D-05 Legacy Packing Source
```

- D-01 blocks unified cutting actual consumption and reliable fabric actual cost.
- D-03 blocks authoritative completion, Backflush target and unit-cost denominator.
- D-04 blocks authoritative material issue/cost when both modes exist.
- D-06 blocks WIP asset and production accounting events.
- D-07 blocks valued FG receipt.
- D-08 blocks valued FG issue.
- D-09 blocks authoritative cost-per-unit and final unit variance/margin.
- D-10 blocks Shipment COGS journal.
- D-11 blocks safe correction and reversal after operational/accounting posting.
- D-02 and D-05 do not select current operational authority, but they block closure of affected historical records.

## 5. Implementation impact by decision

| ID | If approved | Modules/services/controllers/models/database/APIs/UI/tests | Migration and history | If kept undefined |
|---|---|---|---|---|
| D-01 | Enforce selected sole consumption source and completion semantics | CuttingService, LayExecutionService, Cutting controllers/models/routes/UI, MO allocations, MaterialIssue/ActualCost tests | Additive migration only if authority/provenance must persist; never rewrite history without D-02 | Unified consumption, wastage and completion remain blocked |
| D-02 | Add approved, auditable historical resolution policy | Cutting/Production, Approval/Audit, optional Inventory adjustment, reconciliation UI/tests | Decision must state metadata-only versus adjustment; preserve original rows | Historical mixed MO remains readable but uncompletable |
| D-03 | Define named physical/accepted/fulfilled quantities and MO completion source | ProductionOutputAuthorityService, ProductionOrder lifecycle/model/controller, Scan/QC/Packing/Reporting, dashboards and tests | Persistence/completion event may require additive schema; legacy `qty_produced` needs explicit compatibility policy | Completion, Backflush authority and denominator remain blocked |
| D-04 | Implement mutually exclusive, delta, replacement, or class-specific semantics | MaterialIssueService/controller, BOM flags, reservations, ITS, ActualCosting, UI/tests | Schema only if reconciliation/provenance requires it; do not rewrite old issues | Mixed-mode inventory/costing remains non-authoritative |
| D-05 | Formalize legacy Packing mutation and source attachment | PackingService/controller/model, QC relationship, routes/UI/tests | Existing nullable FK may suffice; any attachment metadata must be additive; no backfill | Legacy missing-source rows remain compatibility/read-only or ambiguously mutable |
| D-06 | Add approved WIP valuation timing and basis | Inventory, Production scans/WIP, ActualCosting, ValuationBoundary, OperationalPosting, GL mapping/UI/tests | Likely requires persisted valuation/journal lineage; exact schema depends on policy | WIP accounting and Material Issue production journal remain blocked |
| D-07 | Value `PRODUCTION_RECEIPT` using approved basis | Packing, ITS, Actual/Standard Cost, Finance posting/controller/UI/tests | May require cost snapshot/variance/revaluation references; historical FG policy required | FG remains quantity-only; downstream valuation blocked |
| D-08 | Carry approved FG cost through ITS Shipment | Shipping, Inventory, Finance valuation/posting, Shipment UI/tests | May require layer/source reference and reversal lineage; historical Shipment policy required | Shipment remains quantity-only; COGS blocked |
| D-09 | Publish explicitly named unit-cost metric(s) | ActualCostingService/controller, reports/BEP/UI/tests | Computed metric may need no schema; frozen period snapshots may need persistence | Cost/PCS, final variance, margin and some BEP inputs remain partial |
| D-10 | Post COGS at approved trigger/date from approved valuation | ShipmentService, OperationalPostingService, GlPosting/Journal, account mappings, Finance UI/tests | Journal lineage/reversal may use existing schema; valuation source and historical policy required | Full operational GL remains incomplete |
| D-11 | Implement domain-specific reversal/cancellation/timing matrix | Inventory, Receiving, Production, QC/NCR, Packing, Shipping, Finance, controllers/routes/models/UI/tests | Reversal links/status/source references may require additive migrations; no direct historical rewrite | Posted transaction corrections and closed-period handling remain blocked/partial |

## 6. Recommended decision order

This is an ordering recommendation, not a selection of candidates:

1. **D-01 — Fabric Consumption**
2. **D-03 — Whole-MO Production Output**
3. **D-04 — ACTUAL vs BACKFLUSH**
4. **D-02 — Historical Marker/Lay policy**
5. **D-05 — Legacy Packing source policy**
6. **D-06 — WIP Valuation**
7. **D-07 — FG Valuation**
8. **D-09 — Cost per PCS denominator**
9. **D-08 — Shipment Valuation**
10. **D-10 — COGS**
11. **D-11 — Reversal/Cancellation/Timing**, with principles agreed early and final matrix completed after event authorities are selected.

Highest-priority blockers for Iteration 18 are D-01, D-03 and D-04. Accounting implementation must not start from account mappings alone; D-06 through D-10 require explicit policy approval.

## 7. Iteration 17 controls and result

- Source-code changes: NONE.
- Migration/schema: NONE.
- New writer: NONE.
- Inventory/accounting/output authority changes: NONE.
- Historical rewrite/backfill: NONE.
- Legacy endpoint deletion: NONE.
- Business rules invented: NONE.
- Tests: NOT RUN — DOCUMENTATION/AUDIT ITERATION.
- Runtime: DEFERRED — FINAL VERIFICATION PHASE.
- Decision result: D-01 through D-11 remain PENDING unless a future explicit Decision Log entry locks a choice.
- Iteration 18 must implement only explicitly approved and logged decisions.
