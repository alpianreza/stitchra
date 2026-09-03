# STITCHRA — D-07 FINISHED GOODS VALUATION

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `b345c9bced490bb9c8ff33b1ad091ff540c93d40`  
> **Dependency:** D-06 is LOCKED to provisional standard WIP plus actual variance.  
> **Boundary:** No code, migration, valuation posting, backfill, historical rewrite, or production behavior change is authorized.

## 1. Decision question

What cost basis should be assigned when Packing finalization creates FG through ITS `PRODUCTION_RECEIPT`, and how should that value later converge with actual MO cost?

## 2. Evidence separation

### 2.1 Existing behavior

- Packing finalization aggregates Carton matrix quantities and posts ITS `PRODUCTION_RECEIPT` into an FG warehouse.
- The current Packing service sends quantity, style, colorway, size, warehouse, and PCS UOM, but does not send `unit_cost`.
- ITS supports optional `unit_cost` and `total_cost`; when cost is supplied on an inflow, Moving Average is updated.
- Current FG receipt ledger rows can therefore carry quantity without an authoritative cost.
- `ValuationBoundaryService` explicitly reports FG valuation `NOT DEFINED`, no selected valuation source, and posting blocked.
- `OperationalPostingService` blocks `PRODUCTION_RECEIPT` accounting because the FG valuation authority is missing.
- The MO has an immutable approved standard-cost snapshot.
- Actual Cost is computed read-only and has no authoritative `per_pcs` denominator yet.
- No FG valuation layer, cost adjustment event, production receipt journal, actual-cost close, or historical FG backfill exists.

### 2.2 Existing Business Rules and locked decisions

- **BR-005:** Moving Average is the inventory valuation method where cost authority exists.
- **BR-013:** all stock movement is through ITS and ledger is append-only.
- **BR-017:** historical corrections require approved adjustment.
- **BR-080/PF-09:** QC PASS enables Packing; Packing finalization creates FG quantity via `PRODUCTION_RECEIPT`.
- **BR-083:** Shipment is the FG-out/COGS boundary, but does not define FG cost.
- **BR-100:** approved Cost Sheet is the standard-cost source; the MO snapshot is immutable.
- **BR-065:** Packing quantity and FG received quantity are separate named measures.
- **BR-069 / D-06:** open WIP is provisional standard and later reconciles to actual variance.

No locked rule selects standard, actual, hybrid, or provisional-plus-variance as FG value.

### 2.3 Technical implementation

Current quantity path:

```text
QC FINAL PASS
→ Packing List / Carton matrix
→ Packing finalize
→ ITS PRODUCTION_RECEIPT
→ FG stock quantity
```

Current cost path stops before FG:

```text
Immutable MO standard snapshot   ┐
Computed read-only actual cost   ├─ no selected FG unit-cost bridge
Provisional WIP policy (D-06)    ┘
```

The stock ledger supports:

```text
unit_cost  nullable
 total_cost nullable
```

Schema capability is not business authority. Supplying a cost would affect FG Moving Average and downstream Shipment valuation, so it must not be inferred from the mere presence of columns.

### 2.4 Conflict/gap

1. FG quantity can be received while FG cost is null.
2. A standard-cost snapshot exists, but D-09 has not selected a cost-per-PCS denominator.
3. Actual MO cost can be incomplete or arrive after partial Packing receipts.
4. Multiple Packing Lists may create partial FG receipts for one MO.
5. D-06 requires standard-to-actual variance convergence, but the FG transfer/reconciliation event is not yet defined.
6. No rule defines whether prior FG already shipped participates in later variance.
7. Moving Average mechanics exist, but there is no authoritative adjustment source or period rule for FG revaluation.
8. Buyer-owned FG valuation treatment is not explicitly defined beyond BR-001's exclusion from company valuation.
9. Historical FG receipts with null cost must not be backfilled automatically.

## 3. Candidate policies

### Candidate A — STANDARD COST AT FG RECEIPT

Policy:

- Each `PRODUCTION_RECEIPT` is valued at the immutable MO standard manufacturing cost per PCS.
- FG Moving Average uses this standard value.
- Actual MO variance is reported separately and does not revalue FG.

**Strength:** stable and available before receipt; simple partial-receipt handling.  
**Gap:** actual cost does not flow into FG/COGS; variance remains outside inventory value.  
**Dependency:** D-09 must define standard per-PCS denominator and D-10 must define COGS treatment.  
**Classification:** operationally simple, weaker actual convergence.

### Candidate B — ACTUAL COST AT FG RECEIPT

Policy:

- FG is valued only from complete actual MO cost divided by an authoritative FG/output denominator.

**Strength:** economically direct.  
**Gap:** actual cost and denominator may be unavailable when Packing finalizes; partial receipts and late costs can block FG receipt or require recalculation.  
**Dependency:** D-09, MO close/completion, late-cost, and reversal rules must be complete first.  
**Classification:** not currently authority-ready.

### Candidate C — PROVISIONAL STANDARD FG + ACTUAL VARIANCE RECONCILIATION

Policy:

- FG received through `PRODUCTION_RECEIPT` is valued provisionally from the immutable MO standard-cost basis transferred from WIP.
- A named FG/packed quantity and the D-09 denominator rule must be explicit; missing source fails closed.
- When actual MO cost becomes complete under a locked close/timing policy, the system posts append-only variance/revaluation entries rather than editing the original receipt.
- Variance treatment for FG on hand versus already shipped units is controlled by D-08/D-10/D-11.

**Strength:** continues D-06 consistently, supports partial FG availability, and converges to actual through explicit variance.  
**Gap:** requires denominator, close, shipment allocation, COGS, and reversal rules.  
**Classification:** recommended.

### Candidate D — COMPONENT HYBRID FG

Policy:

- Material is transferred at actual issue/return cost while labor/OH/subcon uses standard until actual evidence completes; later component variances reconcile.

**Strength:** uses stronger actual material evidence early.  
**Gap:** component timing, stage allocation, partial output, late subcon, and denominator rules are complex and not defined.  
**Classification:** possible but higher control burden than Candidate C.

### Candidate E — KEEP FG VALUATION UNDEFINED / BLOCK POSTING

Policy:

- Continue FG quantity movement only.
- Keep cost, production receipt journal, Shipment valuation, and COGS blocked.

**Strength:** matches current safe boundary and invents nothing.  
**Gap:** no valued FG inventory or COGS.  
**Classification:** safe deferral, not closure.

## 4. Comparison matrix

| Dimension | A — Standard | B — Actual | C — Standard + variance | D — Component hybrid | E — Undefined |
|---|---|---|---|---|---|
| Cost available at receipt | Yes | Often no | Yes, provisional | Partial | No |
| Aligns D-06 | Partial | No/late | Highest | High | Defers |
| Supports partial receipts | Yes | Difficult | Yes | Complex | Quantity only |
| Uses immutable MO standard | Yes | No | Yes | Partly | No |
| Converges to actual | Reporting only | Immediate when complete | Explicit variance | Component variance | No |
| Requires D-09 | Yes | Yes | Yes | Yes | Deferred |
| Handles late cost | Separate report | Recalculation | Append-only variance | Component variance | Blocked |
| Moving Average impact | Standard | Actual | Provisional + adjustment | Mixed components | None/unknown |
| Historical backfill | No | No | No | No | No |
| Control complexity | Medium | High | High but explicit | Highest | Lowest |

## 5. Recommendation

```text
Recommendation:
C — PROVISIONAL STANDARD FG + ACTUAL VARIANCE RECONCILIATION

Rationale:
- directly continues the locked D-06 WIP policy;
- immutable MO standard cost is available before partial Packing receipts;
- actual cost can arrive late and remains computed/read-only today;
- append-only variance avoids editing original FG receipt history;
- downstream Shipment/COGS handling can be locked explicitly.

Required dependencies before implementation:
- D-09 cost-per-PCS denominator;
- D-08 Shipment valuation allocation;
- D-10 COGS amount/timing;
- D-11 reversal, period, and late-cost handling;
- explicit FG ownership treatment where BR-001 applies.

Historical consequence:
No existing null-cost FG receipt is backfilled or revalued automatically. The policy
is prospective after a separately approved implementation cutover.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — STANDARD COST AT FG RECEIPT
B — ACTUAL COST AT FG RECEIPT
C — PROVISIONAL STANDARD FG + ACTUAL VARIANCE
D — COMPONENT HYBRID FG
E — KEEP UNDEFINED / BLOCK FG VALUATION POSTING
```

## 7. Impact and dependencies

### Impacted modules

Packing/Carton; ITS/FG ledger and balances; Production Order; WIP valuation; Standard/Actual Cost; Shipment; COGS; GL/account mapping; period close; variance reporting.

### Implementation consequence

No implementation is authorized. A later phase may require FG valuation layers, receipt cost source, denominator links, variance/revaluation documents, Moving Average updates, Shipment allocation, journals, period/reversal controls, and tests.

### Historical-data consequence

No historical Packing, `PRODUCTION_RECEIPT`, FG balance, cost, Shipment, or journal row is changed or backfilled by this analysis.

### Dependency state

```text
D-06 = LOCKED
D-07 = PENDING BUSINESS DECISION
        ↓
D-09 Cost per PCS = NEXT only after D-07 is LOCKED
```

## 8. Decision record template

```text
D-07 — Finished Goods Valuation

Status:
PENDING BUSINESS DECISION

Candidates:
A. STANDARD_COST_AT_FG_RECEIPT
B. ACTUAL_COST_AT_FG_RECEIPT
C. PROVISIONAL_STANDARD_FG_PLUS_ACTUAL_VARIANCE
D. COMPONENT_HYBRID_FG
E. KEEP_UNDEFINED_BLOCK_FG_VALUATION

Recommendation:
C — PROVISIONAL STANDARD FG + ACTUAL VARIANCE

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-07 remains **PENDING BUSINESS DECISION** and D-09 must not be closed.