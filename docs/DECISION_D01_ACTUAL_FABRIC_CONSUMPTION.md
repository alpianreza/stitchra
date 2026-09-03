# STITCHRA — D-01 ACTUAL FABRIC CONSUMPTION AUTHORITY

> **Type:** Decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `8654ff0fafa6085f873287c5b86b435eb112c9ed`  
> **Candidates:** A. `MARKER` / B. `LAY_ROLL` / C. keep undefined  
> **Hard boundary:** Existing implementation is evidence, not automatically a business rule. This document does not lock D-01 or change implementation.

## 1. Scope and controls

This analysis re-verifies only D-01: which persisted transaction, if either, should be the sole authority for Actual Fabric Consumption.

Iteration controls:

- Source code changes: **NONE**.
- Migration/schema changes: **NONE**.
- Historical rewrite/backfill: **NONE**.
- Marker/Lay writer behavior changes: **NONE**.
- Inventory/Costing/Backflush changes: **NONE**.
- Runtime tests: **NOT RUN — DOCUMENTATION/DECISION ANALYSIS**.
- D-03 and later decisions are out of scope.

## 2. Re-verified evidence

### 2.1 Official business evidence

The official evidence conflicts and therefore does not currently establish one sole authority:

- **BR-031 — LOCKED:** estimated and actual consumption are stored separately; actual comes from Marker realization; actual costing uses Marker realization plus leftover return.
- **BR-041 — DEFAULT:** actual Fabric issue is per Roll from Lay; cheap Trim may use Backflush by material class.
- **PF-04 — LOCKED flow:** Material Issue is actual per Roll and creates ITS `MATERIAL_ISSUE`.
- **PF-05 — LOCKED flow:** Lay records Rolls used; Marker records marker length and efficiency; leftover is `initial length − Σ Lay usage`.
- **BR-042 — LOCKED:** leftover must return per Roll and wastage enters Actual Cost.
- **BR-013 — LOCKED:** inventory movement is ITS-only and append-only.
- **BR-120 — LOCKED:** end-to-end traceability explicitly includes `MI line → lay_rolls → bundle`.
- **OBD-006/BR-053:** locks shade compatibility and preserves existing issue/dispatch/consumption controls, but does not select Marker or Lay Roll.

Therefore:

```text
BR-031                         → MARKER evidence
BR-041 + PF-05 + BR-120        → LAY_ROLL evidence
Binding sole-authority decision → NOT PRESENT
```

### 2.2 Marker implementation

- Model: `Modules\Cutting\Models\MarkerLog`.
- Writer: `CuttingService::recordMarker()`.
- Controller: `CutOrderController::recordMarker()`.
- Route: `POST cutting/orders/{cutOrder}/markers` with `cutting.marker.execute`.
- Persisted quantity: `qty_fabric_used_use` plus compatibility `qty_fabric_used_m`.
- Persisted context: Cut Order, Fabric Roll, use-UOM, marker length, plies, efficiency, creator.
- Transaction locks: Cut Order, MO, Fabric Roll, and `fabric_dispatch_balances` row.
- Eligibility: Cut Order `IN_PROGRESS`; Roll `RELEASED`; Roll belongs to Fabric BOM snapshot; dispatch exists; used quantity cannot exceed eligible dispatch or physical remaining.
- Immediate mutations:
  - insert `marker_logs`;
  - increment `fabric_dispatch_balances.qty_consumed`;
  - call `FabricRoll::consumeUse()` to reduce `qty_remaining_use` and meter equivalent;
  - audit Cut Order update with `consumption_path=LEGACY_MARKER`.
- Completion: `CuttingService::complete()` aggregates Marker Logs by material and **increments** `mo_material_allocations.qty_consumed`; `actual_consumption_per_pcs = Marker usage / Cut Order qty_cut`.
- Downstream: legacy Bundle generation is from Cut Order Line, not from a Marker Log. There is no Marker→Cut Output foreign key.
- Source/idempotency key: Marker Log has a primary key and indexes, but no unique business key preventing repeated Marker records for the same Cut Order/Roll. Dispatch and physical ceilings constrain total quantity; they do not make the Marker request idempotent.

### 2.3 Lay Roll implementation

- Models: `Lay`, `LayRoll`, `CutOutput`, and `Bundle`.
- Writer: `LayExecutionService::addRollInternal()`.
- Controller: `LayController`.
- Routes:
  - `POST cutting/orders/{cutOrder}/lays`;
  - `POST cutting/lays/{lay}/rolls`;
  - `POST cutting/lays/{lay}/outputs`;
  - `POST cutting/outputs/{cutOutput}/bundles`;
  - `POST cutting/lays/{lay}/complete`.
- Persisted quantity: `lay_rolls.qty_used` in exact Fabric Roll use-UOM.
- Persisted context: company, Lay, Fabric Roll, UOM, shade override state, creator.
- Transaction locks: Lay, Fabric Roll, and dispatch row; Cut Order state is checked through the locked Lay context. Marker evidence is checked before and after the shared Roll/dispatch locks.
- Eligibility: Lay/Cut Order active; Roll `RELEASED`; dispatch exists; UOM equals dispatch/Fabric Roll use-UOM; quantity cannot exceed eligible dispatch or physical remaining; BR-053 shade rule applies.
- Immediate mutations:
  - insert `lay_rolls`;
  - increment `fabric_dispatch_balances.qty_consumed`;
  - call `FabricRoll::consumeUse()`;
  - advance Lay to `IN_PROGRESS`;
  - audit the Lay Roll with `consumption_path=LAY_ROLL`.
- Completion: `completeLay()` validates exact Bundle totals and calls `syncActualConsumption()`; this aggregates all Lay Rolls on the MO and **sets** `mo_material_allocations.qty_consumed`; denominator is all MO Cut Outputs.
- Downstream: `Lay → CutOutput → Bundle`; new Bundle generation requires a Cut Output.
- Source/idempotency key: unique `(lay_id, fabric_roll_id)`. The same Roll can still be used across multiple Lays, subject to shared dispatch and physical ceilings.

### 2.4 Inventory, dispatch, return, and costing

- `MaterialIssueService::issue()` creates an ACTUAL Material Issue from one exact reservation, posts ITS `MATERIAL_ISSUE`, increments reservation `qty_issued`, increments MO `qty_issued`, and increases dispatch `qty_dispatched` for Roll-tracked Fabric.
- ITS source key is `(company_id, movement_type, source_document_type, source_document_id)`; for issue it is the Material Issue document, not Marker or Lay Roll.
- `fabric_dispatch_balances` has unique `(production_order_id, roll_id)` and enforces `qty_consumed + qty_returned <= qty_dispatched`.
- `FabricRoll::consumeUse()` is called by both Marker and Lay Roll. It reduces physical remaining but creates no ITS movement.
- `returnLeftover()` computes returnable quantity as `dispatched − consumed − returned`; return must close all remaining eligible dispatch and posts ITS `PRODUCTION_RETURN`.
- `ActualCostingService` does **not** value material from Marker Logs or Lay Rolls. It reads valued ITS `MATERIAL_ISSUE` minus valued ITS `PRODUCTION_RETURN`. It explicitly reports `marker_vs_lay_consumption_authority=NOT_DEFINED`; separate wastage value is also not defined.
- Backflush does not read Marker or Lay Roll. It uses `grossPerPcs × production_orders.qty_produced`, subtracts prior BACKFLUSH only, consumes reservations, and posts ITS `MATERIAL_ISSUE`.

## 3. Actual write graphs

### 3.1 Shared upstream inventory/dispatch graph

```text
Stock Reservation
  writer: MO release
  key: company + MO + material + warehouse + stock dimensions
        ↓
ACTUAL Material Issue
  writer: MaterialIssueService::issue()
  transaction: DB transaction
  qty/UOM: reserved Fabric Roll quantity in material use-UOM
  locks: MO + reservation + Roll + ITS balance
  audit: Material Issue create
        ├──────────────→ ITS MATERIAL_ISSUE
        │                 source key: company + movement type + material_issues + issue ID
        │                 effect: RM stock on_hand decreases; valued ledger may be recorded
        └──────────────→ fabric_dispatch_balances.qty_dispatched
                          key: MO + Roll; same warehouse/UOM enforced
```

Marker and Lay Roll operate **after** Material Issue. Neither is an ITS stock movement.

### 3.2 Marker write graph

```text
POST /cutting/orders/{cutOrder}/markers
        ↓
CutOrderController::recordMarker()
        ↓
CuttingService::recordMarker() [DB transaction]
  locks: Cut Order → MO → Fabric Roll → dispatch row
  guard: no Lay Roll evidence on the MO
  qty: input qty converted to Fabric Roll use-UOM
        ↓
marker_logs [INSERT]
  reader: CuttingService::complete(); audit/report compatibility
  business key: none beyond row ID
        ├──────────────→ fabric_dispatch_balances.qty_consumed [INCREMENT]
        │                 key: MO + Roll
        ├──────────────→ FabricRoll::consumeUse() [DECREMENT physical remaining]
        │                 UOM: use-UOM + meter equivalent
        └──────────────→ AuditService on Cut Order
                          consumption_path=LEGACY_MARKER
        ↓ completion
CuttingService::complete()
  aggregate Marker Logs by material for this Cut Order
        ↓
mo_material_allocations.qty_consumed [INCREMENT]
actual_consumption_per_pcs [SET using this Cut Order output]
        ↓
legacy Bundle path from Cut Order Line
  no Marker→CutOutput or Marker→Bundle source link
```

### 3.3 Lay Roll write graph

```text
POST /cutting/orders/{cutOrder}/lays
        ↓
Lay
        ↓
POST /cutting/lays/{lay}/rolls
        ↓
LayController::addRoll()
        ↓
LayExecutionService::addRollInternal() [DB transaction]
  locks: Lay → Fabric Roll → dispatch row
  guard: no Marker evidence on the MO, including post-lock recheck
  qty: exact Fabric Roll/dispatch use-UOM
        ↓
lay_rolls [INSERT]
  unique key: Lay + Fabric Roll
        ├──────────────→ fabric_dispatch_balances.qty_consumed [INCREMENT]
        │                 key: MO + Roll
        ├──────────────→ FabricRoll::consumeUse() [DECREMENT physical remaining]
        └──────────────→ AuditService on Lay Roll
                          consumption_path=LAY_ROLL
        ↓
CutOutput [Lay + Cut Order Line + qty]
        ↓
Bundle [mandatory CutOutput link for new path]
        ↓ completion
LayExecutionService::completeLay()
  aggregate every Lay Roll for MO
        ↓
mo_material_allocations.qty_consumed [SET/REPLACE with aggregate]
actual_consumption_per_pcs [SET using aggregate MO Cut Outputs]
```

### 3.4 Reader/authority split

```text
Marker/Lay Roll → operational consumed quantity and Roll remaining
Material Issue/Return ITS ledger → inventory quantity and material cost
Backflush → legacy output × BOM; independent of Marker/Lay Roll
```

D-01 selects operational Actual Fabric Consumption authority. It does not replace ITS inventory authority.

## 4. Conflict answers

1. **Do both mutate physical consumption?** **YES — EVIDENCE.** Both increment the same dispatch `qty_consumed` and call `FabricRoll::consumeUse()`.
2. **Can both be used on the same MO now?** **NO for new execution.** Bidirectional guards block Marker-after-Lay and Lay-after-Marker. Historical coexistence remains readable.
3. **Can one overwrite the other?** **Current mixed completion is blocked.** Semantically, Marker completion increments MO allocation while Lay completion sets it; before/currently persisted historical mixed results can therefore be order-dependent, but actual production data is not runtime-inspected.
4. **Does double consumption occur?** **New provable mixed execution is blocked.** Historical duplicate representation is possible by schema/history and explicitly anticipated by the guard, but its actual occurrence/amount is `UNKNOWN` without database evidence. Dispatch/physical limits prevent consumption beyond available quantity; they do not determine which path is truthful.
5. **Does completion sequence affect results?** **CONFLICT by semantics.** Marker adds per Cut Order; Lay replaces with all-MO aggregate. Current mixed guards stop a new sequence from completing; legacy results may have been sequence-dependent.
6. **Can consumption be reconstructed deterministically?** Marker: deterministic by MO/Roll/material from Marker rows, but not reliably through Cut Output/Bundle. Lay Roll: deterministic through Roll→Lay→Cut Output→Bundle for the new path, though a Lay with multiple Cut Outputs has no persisted allocation of each Roll quantity to one specific output line.
7. **Does historical production data use both paths?** **UNKNOWN.** Repository schema and code support Marker-only, Lay-only, and historical mixed compatibility, but no production database was inspected.
8. **Does the inventory ledger record either path?** **NO.** ITS records Material Issue and Production Return. Marker/Lay Roll mutate the operational dispatch/physical-Roll subledger without creating another ITS movement.
9. **What does Actual Cost read?** Valued ITS Material Issue minus valued ITS Production Return. It does not select Marker or Lay Roll; wastage and Marker-vs-Lay authority remain undefined.
10. **What does Backflush read?** Neither path. It reads legacy `qty_produced × BOM grossPerPcs` and prior BACKFLUSH issues.

## 5. Candidate A — MARKER

### Operational

If selected, actual cutting consumption occurs when `recordMarker()` persists measured Fabric used. Marker has quantity, Roll, UOM, length, plies, and efficiency, so it can represent measured consumption. However, the implementation does not prove that `qty_fabric_used` is always derived from a defined physical formula; it is input and validated only against dispatch/physical ceilings.

### Inventory and leftover

- Dispatch consumed and Fabric Roll remaining already change at Marker record time.
- Material Issue remains the ITS stock-out; Marker must not create a second ITS movement.
- Leftover remains `dispatched − Marker consumed − returned` and is returned through ITS.
- Separate wastage amount/quantity classification remains undefined.
- Lay Roll must cease being a competing consumption writer for new transactions or be explicitly reclassified as non-authoritative allocation evidence.

### Traceability

```text
Fabric Roll → Marker Log → Cut Order
```

is reliable. The requested chain:

```text
Fabric Roll → Marker → Cut Output → Bundle
```

is **PARTIAL**, because Marker has no Lay/Cut Output foreign key and legacy Bundle generation is from Cut Order Line. It cannot reconstruct which Marker quantity produced which Cut Output/Bundle without a future approved relationship or a documented coarser Cut Order-level interpretation.

### Compatibility and migration impact

- Marker-only history: strongest compatibility.
- Lay and Lay Roll may remain spreading/shade/allocation evidence and source of Cut Output/Bundle, but their `qty_used` must not remain a second actual writer.
- Lay-only history cannot be converted to Marker history by assumption.
- No migration is automatically required to make a service-level authority choice. Additive authority/provenance metadata may be needed if the owner requires persisted path versioning.
- No historical backfill or quantity rewrite is permitted. Mixed and Lay-only history require a separate D-02 policy.

### Main risks

- Conflicts with BR-041/PF-05/BR-120 direction.
- Weaker source lineage from actual consumption to Cut Output/Bundle.
- Existing Lay Roll quantity would have to be clearly reclassified without destroying operational shade/spreading evidence.
- Marker requests lack a deterministic business idempotency key.

## 6. Candidate B — LAY_ROLL

### Operational

If selected, actual cutting consumption occurs when a Fabric Roll quantity is assigned/used on an active Lay. Quantity is persisted as `lay_rolls.qty_used` in exact use-UOM, bounded by issued dispatch and physical remaining, and protected by shade rules. Lay completion aggregates the MO's Lay Rolls and derives per-piece consumption from Cut Outputs.

### Inventory and leftover

- Dispatch consumed and Fabric Roll remaining already change at Lay Roll creation.
- Material Issue remains the ITS stock-out; Lay Roll must not create another ITS movement.
- Leftover remains `dispatched − Lay Roll consumed − returned` and is posted back through ITS.
- Marker must cease being a competing consumption writer for new transactions and become efficiency/planning or legacy compatibility evidence.
- Separate wastage classification/value remains undefined.

### Traceability

The new path is persisted as:

```text
Fabric Roll
→ ACTUAL Material Issue line / reservation
→ dispatch balance
→ Lay Roll
→ Lay
→ Cut Output
→ Bundle
```

This is **EVIDENCE / strongest existing reconstructability**. Limit: if one Lay has multiple Cut Outputs, the schema does not allocate each Roll quantity to an individual Cut Output; lineage is deterministic at Lay level, not necessarily proportional output-line consumption.

### Compatibility and migration impact

- Lay-only history: strongest compatibility.
- Marker/Marker Log remains readable as legacy and efficiency evidence; legacy endpoint must be preserved until a separate lifecycle decision.
- `CuttingService::complete()` can no longer remain a competing actual writer if this candidate is locked.
- BR-031 must be formally amended or clarified; selecting Lay Roll while leaving the locked Marker statement unchanged would preserve a governance contradiction.
- No migration is automatically required for new service behavior. Additive authority/version metadata may be justified if path provenance must be explicit.
- No historical backfill or rewrite is permitted. Marker-only and mixed history require D-02.

### Main risks

- Direct conflict with the currently locked wording of BR-031.
- Historical Marker reports and completion semantics cannot be silently reinterpreted.
- Multiple Lay completion calls and aggregate synchronization require explicit idempotency/lifecycle verification when implementation is authorized.

## 7. Side-by-side matrix

| Dimension | MARKER | LAY_ROLL |
|---|---|---|
| Existing business evidence | **EVIDENCE:** BR-031 LOCKED | **EVIDENCE:** BR-041 DEFAULT; PF-05 and BR-120 LOCKED flow |
| Sole business authority | **CONFLICT / NOT DEFINED** | **CONFLICT / NOT DEFINED** |
| Existing writer | **DEFINED:** `recordMarker()` | **DEFINED:** `addRollInternal()` |
| Physical consumption representation | **PARTIAL:** measured Fabric-used input plus length/plies | **EVIDENCE:** exact Roll quantity used on Lay |
| Quantity authority today | **CONFLICT:** writer exists, authority open | **CONFLICT:** writer exists, authority open |
| UOM | **DEFINED:** converts input to Roll use-UOM; keeps meter compatibility | **DEFINED:** exact Roll/dispatch use-UOM |
| Inventory interaction | **DEFINED boundary:** no ITS movement; after Material Issue | **DEFINED boundary:** no ITS movement; after Material Issue |
| Dispatch interaction | **DEFINED:** increments same MO×Roll consumed balance | **DEFINED:** increments same MO×Roll consumed balance |
| Fabric Roll interaction | **DEFINED:** reduces physical remaining | **DEFINED:** reduces physical remaining |
| Waste/leftover | **PARTIAL:** leftover derivable from dispatch minus Marker usage; wastage separate source undefined | **PARTIAL/EVIDENCE:** PF-05 bases leftover on Lay usage; wastage separate source undefined |
| Cut Output traceability | **NOT DEFINED:** no Marker→CutOutput FK | **DEFINED at Lay level:** Lay→CutOutput |
| Bundle traceability | **PARTIAL:** legacy Bundle from Cut Order Line, not Marker | **DEFINED for new path:** CutOutput→Bundle |
| Actual Cost dependency | **NOT DIRECT:** ITS issue−return; Marker/Lay authority flagged undefined | **NOT DIRECT:** ITS issue−return; Marker/Lay authority flagged undefined |
| Backflush dependency | **NONE:** does not read Marker | **NONE:** does not read Lay Roll |
| Historical compatibility | **EVIDENCE:** legacy Marker-first architecture | **PARTIAL:** newer path; Marker history needs compatibility policy |
| Duplicate writer risk | **CONFLICT:** competes with Lay Roll; new mixing currently blocked | **CONFLICT:** competes with Marker; new mixing currently blocked |
| Writer idempotency | **PARTIAL:** no unique Cut Order/Roll business key | **PARTIAL:** unique Lay/Roll, but Roll can span Lays |
| Completion semantics | **CONFLICT:** increments MO allocation | **CONFLICT:** sets MO aggregate |
| Implementation complexity if selected | Disable/reclassify Lay Roll consumption while preserving Lay outputs and shade evidence | Disable/reclassify Marker consumption while preserving legacy reads/efficiency |
| Migration risk | **PARTIAL:** none necessarily; provenance/history policy may require additive schema | **PARTIAL:** none necessarily; provenance/history policy may require additive schema |
| Auditability | **PARTIAL:** row creator plus Cut Order summary audit | **EVIDENCE:** Lay Roll audit, user, exact Lay/Roll/UOM, optional approved shade override |
| Reconstructability | **PARTIAL:** Roll→Marker→Cut Order; weak output lineage | **EVIDENCE:** Roll→Lay→CutOutput→Bundle; exact at Lay level |
| Future extensibility | **PARTIAL:** requires new lineage to match Lay/output model | **EVIDENCE:** supports shade, multi-Roll Lay, Cut Output and Bundle lineage |

## 8. Downstream consequence analysis

### 8.1 If MARKER wins

- **Lay:** remains spreading, layer, shade, and Cut Output container.
- **Lay Roll:** remains allocation/shade evidence but cannot remain an authoritative consumption/physical writer without an explicitly defined derived/non-authoritative role.
- **Cut Output/Bundle:** new Lay path can remain; consumption-to-output lineage remains coarser unless Marker obtains an approved link.
- **Sewing/Finishing/QC/Packing:** quantity flow can remain Bundle-based; no direct business change required by D-01 alone.
- **Actual Cost:** current issue−return valuation remains; any future consumption/wastage report must use Marker only.
- **Backflush:** unchanged and still blocked by D-03/D-04 authority gaps.
- **ITS:** unchanged as inventory movement authority.
- **Dispatch/Fabric Roll:** only Marker may mutate consumed/remaining for new actual consumption; Lay Roll's competing mutations must be removed or reclassified only after approval.
- **Governance:** BR-041/PF-05/BR-120 require clarification or amendment.

### 8.2 If LAY_ROLL wins

- **Marker/Marker Log:** retained as efficiency/planning and legacy evidence; no new actual-consumption mutation through Marker.
- **Legacy completion:** Marker-based allocation increment cannot remain an authoritative writer for new flow.
- **Cut Output/Bundle:** current direct Lay lineage is preserved.
- **Sewing/Finishing/QC/Packing:** existing Bundle-based downstream flow remains.
- **Actual Cost:** current issue−return valuation remains; future consumption/wastage quantity reports use Lay Roll only.
- **Backflush:** unchanged and still requires D-03/D-04.
- **ITS:** unchanged as inventory movement authority.
- **Dispatch/Fabric Roll:** only Lay Roll may mutate consumed/remaining for new actual consumption; Marker's competing mutations must stop after approval.
- **Governance:** BR-031 must be formally amended or clarified before implementation.

Neither candidate by itself defines WIP valuation, FG valuation, cost per unit, COGS, Backflush convergence, or historical reconciliation.

## 9. Historical data classification

No production database was inspected. Classifications below describe what the repository can establish, not row counts.

| Historical class | Classification | Reason |
|---|---|---|
| Marker-only with intact Roll/Cut Order lineage | **COMPATIBILITY REQUIRED** | Persisted and reconstructable at Cut Order/Roll level; policy depends on chosen future authority |
| Lay-only with intact Lay Roll/Cut Output/Bundle lineage | **COMPATIBILITY REQUIRED** | Persisted and reconstructable at Lay level; policy depends on chosen future authority |
| Mixed Marker/Lay on the same MO | **BLOCKED / RECONCILIATION REQUIRED** | Current completion blocks coexistence; source precedence and amount are not defined |
| Marker Bundle without Cut Output | **COMPATIBILITY REQUIRED** | Nullable Cut Output is intentional for historical rows |
| Missing Roll/UOM/output lineage beyond nullable compatibility design | **UNKNOWN** | Requires database inspection; cannot be inferred from repository code |
| Both paths recorded against the same dispatch | **UNKNOWN / RECONCILIATION REQUIRED if present** | Schema/history permits evidence; current code prevents new mixing but database occurrence was not checked |
| Direct historical quantity rewrite | **BLOCKED** | BR-013/017 require append-only reversal/approved adjustment; D-02 must define policy |

`SAFE` cannot be assigned to a historical quantity population without runtime/database evidence and an approved D-02 policy.

## 10. Recommendation

```text
Recommendation:
LAY_ROLL

Primary evidence:
PF-05 defines Roll usage and leftover from Lay usage, while Marker records length/efficiency.
BR-120's locked traceability chain explicitly uses lay_rolls.
The persisted new path provides Fabric Roll → dispatch → Lay Roll → Lay → Cut Output → Bundle lineage.

Secondary evidence:
BR-041 describes actual Fabric per Roll from Lay.
Lay Roll enforces exact Roll use-UOM, dispatch eligibility, physical remaining, BR-053 shade controls, and audit at the allocation transaction.

Main risk:
BR-031 is LOCKED and explicitly names Marker realization as actual consumption. LAY_ROLL cannot be implemented as sole authority until BR-031 is formally amended or clarified by the business owner.

Historical risk:
Marker-only and mixed historical data cannot be reinterpreted, backfilled, or rewritten automatically. D-02 must define compatibility and reconciliation.

Implementation consequence:
After an explicit lock only, Marker becomes planning/efficiency plus legacy evidence; its competing dispatch/Fabric Roll/MO-consumption writes must stop for new transactions. ITS Material Issue/Return remains unchanged.
```

This recommendation is based on process/traceability evidence, not on the fact that Lay Roll is newer. It is **not** the decision and does not supersede BR-031.

## 11. Decision record template

```text
D-01 — Actual Fabric Consumption Authority

Status:
PENDING BUSINESS DECISION

Candidates:
A. MARKER
B. LAY_ROLL
C. KEEP UNDEFINED

Evidence:
- Marker has BR-031 and legacy writer/completion evidence.
- Lay Roll has BR-041/PF-05/BR-120 and stronger persisted Roll→Lay→Cut Output→Bundle evidence.
- Both currently mutate dispatch and physical Roll; current guards prevent new mixed use.
- ITS records Material Issue/Return, not Marker/Lay Roll.
- Actual Cost currently reads valued ITS issue−return; Backflush reads neither path.

Recommendation:
LAY_ROLL — conditional on formal BR-031 amendment/clarification and a separate D-02 historical policy.

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

## 12. Required owner response

Choose exactly one:

```text
A — MARKER
B — LAY ROLL
C — KEEP UNDEFINED
```

Until the owner responds and the Decision Log is updated, D-01 remains **PENDING BUSINESS DECISION** and implementation remains blocked.