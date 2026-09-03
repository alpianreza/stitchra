# STITCHRA — D-09 COST PER PCS DENOMINATOR

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `1cd5493f9ea4cd407fa4f1d1e58a40817709e182`  
> **Dependency:** D-07 is LOCKED to provisional standard FG plus actual variance.  
> **Boundary:** No code, migration, costing persistence, valuation posting, backfill, or production behavior change is authorized.

## 1. Decision question

Which named quantity is the authoritative denominator for official Actual Manufacturing Cost per PCS, and how must other per-unit operational metrics be labeled?

D-09 must not reintroduce one universal production output after D-03 locked separate named measures.

## 2. Evidence separation

### 2.1 Existing behavior

- `ActualCostingService` computes total actual cost per MO from valued Material Issue less Production Return, production output/rates, and subcon fees.
- The service publishes `actual.per_pcs = null` and `cost_per_unit_denominator = NOT_DEFINED`.
- For labor/OH calculation, the service currently prefers unambiguous final-routing-operation OUT scan quantity and falls back to legacy `production_orders.qty_produced`.
- The legacy fallback is non-authoritative under D-03.
- Cut Output, Sewing OUT, Finishing OUT, QC FINAL PASS lot, Packing quantity, FG receipt, and Shipment quantity are persisted with different meanings.
- FG quantity is authoritatively created by ITS `PRODUCTION_RECEIPT` from Packing.
- D-07 now locks provisional standard FG with later actual variance, but actual unit-cost denominator remains pending.
- No MO completion/close rule currently freezes the final denominator.

### 2.2 Existing Business Rules and locked decisions

- **BR-009:** actual cost is per MO; labor and overhead use output, but no per-PCS denominator is selected.
- **BR-017:** historical corrections require approved adjustment.
- **BR-080:** QC FINAL PASS is Packing eligibility, not a universal output.
- **BR-100:** approved Cost Sheet is standard cost.
- **BR-065 / D-03:** no generic whole-MO quantity is authoritative; each stage has a named measure.
- **BR-069 / D-06:** WIP is provisional standard plus later actual variance.
- **BR-105 / D-07:** FG is provisional standard plus later actual variance; D-09 must select the unit-cost denominator.

No locked rule selects planned, cut, sewn, finished, QC accepted, packed, FG received, shipped, or multiple denominators as the official cost-per-PCS basis.

### 2.3 Technical implementation

Current computed cost structure:

```text
actual material + labor + overhead + subcon + other
→ defined total per MO
→ per_pcs = NULL
```

Available denominator evidence:

```text
qty_planned
Cut Output qty
Final Sewing OUT qty
Finishing OUT evidence
QC FINAL PASS lot_qty
Packing/Carton qty
ITS PRODUCTION_RECEIPT qty_in
ITS SHIPMENT qty_out
```

Only `PRODUCTION_RECEIPT` is the defined FG inventory quantity event. It is append-only through ITS and tied to Packing List/Carton matrix. Shipment is downstream consumption of FG, not production creation.

### 2.4 Conflict/gap

1. Planned quantity is a target, not actual output.
2. Cut quantity precedes Sewing, Finishing, QC, reject, rework, and packing losses.
3. Sewing output is strong production evidence but not accepted FG.
4. Finishing has no mandatory terminal completion definition.
5. QC `lot_qty` is inspection population and may repeat across cycles.
6. Packing quantity is accepted/packed but FG authority is finalized only through ITS receipt.
7. FG receipt may be partial across multiple Packing Lists.
8. Shipment quantity excludes FG still on hand and therefore is not a production denominator.
9. Total actual MO cost can change after partial FG receipt due to late labor/OH/subcon/returns.
10. Rework, reject, scrap, second-grade, and grade-allocation cost arithmetic is not fully defined.
11. MO close/timing and reversal remain pending D-11.

## 3. Candidate denominators

### Candidate A — PLANNED QUANTITY

```text
actual cost per PCS = total actual MO cost / qty_planned
```

**Strength:** always available and stable.  
**Gap:** hides yield loss and is not actual output; unsuitable for FG inventory unit cost.  
**Classification:** planning variance KPI only, not recommended as official actual denominator.

### Candidate B — FINAL SEWING OUT

```text
actual cost per sewn PCS = total actual MO cost / cumulative Final Sewing OUT
```

**Strength:** strongest current shop-floor production evidence and aligns with BR-007.  
**Gap:** includes units not yet finished/QC accepted/received as FG; does not reconcile directly to FG inventory.  
**Classification:** operational manufacturing KPI, not ideal as FG valuation denominator.

### Candidate C — QC ACCEPTED QUANTITY

```text
actual cost per accepted PCS = total actual MO cost / authoritative QC accepted quantity
```

**Strength:** reflects quality acceptance before Packing.  
**Gap:** current QC persists lot quantity/verdict, not a dedicated append-only accepted-output quantity; cycles may repeat.  
**Classification:** conceptually useful, current authority insufficient.

### Candidate D — FG RECEIVED PRIMARY + LABELED NAMED KPIs

```text
Official actual manufacturing cost per FG PCS
= complete actual MO manufacturing cost / cumulative authoritative FG received quantity
```

Policy:

- denominator is company-owned ITS `PRODUCTION_RECEIPT.qty_in` traceable to the MO;
- during an open MO, FG remains provisional standard under D-07;
- actual per-FG-PCS is finalized only when actual cost and denominator are frozen by a later locked close/timing rule;
- incomplete grade/scrap/rework allocation fails closed;
- other denominators may be reported only with explicit labels such as cost per planned, cut, sewn, accepted, packed, or shipped PCS and must not be presented as inventory unit cost.

**Strength:** directly reconciles to valued FG inventory and preserves D-03 named measures.  
**Gap:** requires MO close, partial receipt, grade/scrap, late-cost, and reversal controls.  
**Classification:** recommended.

### Candidate E — MULTIPLE NAMED UNIT COSTS, NO PRIMARY

Policy:

- publish separate cost-per-planned/cut/sewn/QC/packed/FG/shipped metrics;
- do not select one official inventory denominator.

**Strength:** highest analytical transparency.  
**Gap:** D-07 actual FG reconciliation and D-08 Shipment valuation remain without one accounting unit cost.  
**Classification:** useful reporting model but does not close the accounting denominator.

### Explicit alternatives not recommended

- **Packed quantity:** close to FG receipt but is not the inventory ledger authority until finalized.
- **Shipped quantity:** is a downstream consumption measure and would overstate unit cost while FG remains on hand.
- **Legacy `qty_produced`:** prohibited as generic authority by D-03.

## 4. Comparison matrix

| Dimension | A — Planned | B — Sewing | C — QC accepted | D — FG received primary | E — Multiple only |
|---|---|---|---|---|---|
| Persisted authority today | Yes target | Yes scans | Partial/cycle ambiguity | Yes ITS quantity | Uses several |
| Reconciles to FG inventory | No | No | Partial | Highest | No primary |
| Reflects yield loss | Indirectly no | Cutting→Sewing only | Accepted yield | Yes at FG boundary | Multiple views |
| Supports D-07 variance | Weak | Weak | Medium | Highest | Blocked accounting basis |
| Partial production | Stable but misleading | Cumulative | Ambiguous cycles | Cumulative receipts; close needed | Multiple |
| Late-cost handling | Close needed | Close needed | Close needed | Provisional then actual at close | Each metric changes |
| D-03 alignment | Named target | Named stage | Named stage | Named FG + labeled KPIs | Highest analytical |
| Inventory/COGS usability | Low | Low | Medium | Highest | Low without primary |
| Historical backfill | No | No | No | No | No |

## 5. Recommendation

```text
Recommendation:
D — FG RECEIVED PRIMARY + LABELED NAMED KPIs

Official denominator:
Cumulative company-owned ITS PRODUCTION_RECEIPT quantity traceable to the MO.

Timing:
While MO/cost is open, use D-07 provisional standard. Final actual cost per FG PCS
is calculated only after D-11 freezes cost and denominator. Late changes use
append-only variance/reversal, not receipt edits.

Other metrics:
Cost per planned/cut/sewn/QC/packed/shipped PCS may exist only as clearly labeled
analytical KPIs and are not FG inventory unit cost.

Fail-closed conditions:
Missing MO trace, incomplete actual cost, unresolved grade/scrap/rework allocation,
missing denominator, or inconsistent receipt history.

Historical consequence:
No historical denominator or unit cost is backfilled automatically.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — PLANNED QUANTITY
B — FINAL SEWING OUT
C — QC ACCEPTED QUANTITY
D — FG RECEIVED PRIMARY + LABELED NAMED KPIs
E — MULTIPLE NAMED UNIT COSTS, NO PRIMARY
```

## 7. Impact and dependencies

### Impacted modules

Actual Cost; Standard Cost; MO close; Packing/Carton; ITS FG receipt; WIP/FG valuation; Shipment; COGS; variance/revaluation; GL; reporting/UI.

### Implementation consequence

No implementation is authorized. A later phase may require denominator snapshots, MO/receipt aggregation, grade allocation, provisional/final states, append-only variance, close/period/reversal controls, and labeled KPI reporting.

### Historical-data consequence

No historical cost, output, Packing, FG receipt, Shipment, or journal row is changed or backfilled by this analysis.

### Dependency state

```text
D-07 = LOCKED
D-09 = PENDING BUSINESS DECISION
        ↓
D-08 Shipment Valuation = NEXT only after D-09 is LOCKED
```

## 8. Decision record template

```text
D-09 — Cost per PCS Denominator

Status:
PENDING BUSINESS DECISION

Candidates:
A. PLANNED_QUANTITY
B. FINAL_SEWING_OUT
C. QC_ACCEPTED_QUANTITY
D. FG_RECEIVED_PRIMARY_PLUS_LABELED_NAMED_KPIS
E. MULTIPLE_NAMED_UNIT_COSTS_NO_PRIMARY

Recommendation:
D — FG RECEIVED PRIMARY + LABELED NAMED KPIs

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-09 remains **PENDING BUSINESS DECISION** and D-08 must not be closed.