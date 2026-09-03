# STITCHRA — D-06 WIP VALUATION

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `f94c2c5572b81e94db71d905788a3de0b647ed7e`  
> **Dependency:** D-05 is LOCKED; D-06 precedes FG valuation, cost-per-PCS, shipment valuation, and COGS.  
> **Boundary:** No code, migration, posting, backfill, historical rewrite, or production behavior change is authorized.

## 1. Decision question

What cost basis should Stitchra assign to WIP while an MO moves from Material Issue through Cutting, Sewing, and Finishing, and when should WIP value be recognized or reconciled?

Quantity authority and valuation authority must remain separate.

## 2. Evidence separation

### 2.1 Existing behavior

- ITS `MATERIAL_ISSUE` removes RM quantity and can carry Moving Average `unit_cost`/`total_cost` for company-owned material.
- ITS `PRODUCTION_RETURN` can restore material quantity and cost when the source issue cost is unambiguous.
- Production scans persist Bundle/stage/operation quantity evidence.
- `wip_transfers` persists append-only quantity transfers for CUTTING→SEWING and SEWING→FINISHING.
- WIP transfers do not create ITS stock movements, valuation layers, or journals.
- `ValuationBoundaryService` explicitly classifies operational WIP as `DEFINED_OPERATIONAL_LINEAGE_ONLY` and valuation as `NOT DEFINED`.
- `OperationalPostingService` blocks the `MATERIAL_ISSUE` accounting event because the WIP debit boundary is not defined, even if an account mapping exists.
- Actual Cost is computed read-only from valued issue/return, output/rates, and subcon fees. It is not a persisted WIP or FG cost ledger.
- No WIP valuation table, stage cost layer, capitalization event, allocation method, close/reopen policy, or operational reversal exists.

### 2.2 Existing Business Rules

- **BR-005:** Moving Average valuation applies to inventory transactions with a defined cost source.
- **BR-013:** all stock movement uses ITS; ledger is append-only.
- **BR-017:** historical corrections require approved adjustment.
- **BR-064:** WIP moves through WIP transfer, not direct quantity edits.
- **BR-009:** actual cost is per MO; material, labor, overhead, and subcon are cost components.
- **BR-100:** approved Cost Sheet is the standard-cost source; MO has an immutable standard-cost snapshot.
- **BR-101/103:** internal GL and period controls are authoritative accounting boundaries.
- **BR-065:** production quantities are separate named measures.

No locked rule states whether WIP is material-only, standard cost, accumulated actual, provisional standard with variance, or intentionally unvalued.

### 2.3 Technical implementation

Operational quantity chain:

```text
ITS MATERIAL_ISSUE (RM out)
→ Bundle/Production Scan
→ WIP Transfer CUTTING→SEWING
→ WIP Transfer SEWING→FINISHING
→ QC/Packing
→ ITS PRODUCTION_RECEIPT (FG in)
```

Available cost evidence:

```text
Material = valued ITS MATERIAL_ISSUE − valued PRODUCTION_RETURN
Labor    = named output × SAM × line rate (computed read-only)
Overhead = named output × SAM × OH rate (computed read-only)
Subcon   = linked subcon fees
Standard = immutable approved Cost Sheet snapshot on MO
```

Missing bridge:

```text
source cost evidence
→ stage allocation
→ WIP valuation layer
→ accounting event/date/period
→ FG transfer/reconciliation
```

The presence of a GL account mapping does not authorize posting because amount, event timing, and reversal policy are incomplete.

### 2.4 Conflict/gap

1. RM inventory can leave through a valued Material Issue while no WIP asset value is recognized.
2. WIP quantity transfers are append-only but carry no cost.
3. Actual labor/OH depends on named output and rates, so cost develops over time and may be incomplete.
4. Standard cost exists before production, but stage allocation among Cutting/Sewing/Finishing is undefined.
5. Subcon cost may arrive after the operational transfer.
6. Material returns and corrections can change the material basis after WIP movement.
7. No rule defines partial completion, equivalent units, reject/rework/scrap treatment, or stage yield.
8. No rule defines capitalization date, GL period, late transaction handling, or reversal.
9. Historical production lacks a valuation layer and must not be backfilled automatically.

## 3. Candidate policies

### Candidate A — MATERIAL-ONLY WIP

Policy:

- WIP value equals valued company-owned Material Issue less valued Production Return.
- Labor, overhead, and subcon are not added to WIP until a later boundary.
- WIP transfers move quantity evidence only; the MO-level material value follows the open MO.

**Strength:** uses the strongest current cost evidence and avoids stage allocation assumptions.  
**Gap:** understates WIP conversion cost and does not value stage progression.  
**Dependency:** D-07 must define when conversion costs enter FG.  
**Classification:** evidence-supported but incomplete manufacturing valuation.

### Candidate B — STANDARD-COST WIP

Policy:

- WIP is valued provisionally from the immutable MO standard-cost snapshot.
- Named stage quantity and an approved stage-allocation profile determine WIP value at each transfer.
- Missing stage allocation fails closed.

**Strength:** stable, available before execution, and aligned with BR-100.  
**Gap:** stage allocation does not currently exist; actual variance is deferred.  
**Dependency:** D-07/D-09/D-10 must define transfer, denominator, and variance treatment.  
**Classification:** operationally practical but requires a new locked allocation rule.

### Candidate C — ACCUMULATED ACTUAL WIP

Policy:

- WIP accumulates actual material, labor, overhead, and subcon cost as evidence is posted.
- Each stage transfer carries the accumulated actual unit/total cost available at that time.

**Strength:** closest to economic cost.  
**Gap:** Actual Cost is currently computed read-only and incomplete; late costs and partial output require recalculation/reversal rules.  
**Dependency:** cost denominator, completion, late transactions, and reversal must be locked first.  
**Classification:** conceptually strong, not currently authority-ready.

### Candidate D — PROVISIONAL STANDARD WIP + ACTUAL VARIANCE RECONCILIATION

Policy:

- Open WIP is valued provisionally using the immutable MO standard-cost snapshot.
- WIP stage quantity uses explicit named production/WIP measures.
- Stage allocation must be explicitly configured and snapshotted; missing allocation fails closed.
- At a later locked completion/FG boundary, actual cost reconciles provisional standard and posts variance through approved accounting rules.
- Source and valuation records remain append-only; corrections use reversal/adjustment.

**Strength:** gives timely WIP value while actual evidence is incomplete, preserves immutable standard, and allows later actual variance.  
**Gap:** requires stage allocation, D-07 FG transfer, D-09 denominator, D-10 COGS, and D-11 reversal/timing decisions.  
**Classification:** strongest balanced recommendation, but not implementation-ready.

### Candidate E — KEEP WIP VALUATION UNDEFINED / BLOCK POSTING

Policy:

- Continue operational WIP quantity lineage only.
- Keep Material Issue→WIP journals and WIP valuation blocked.

**Strength:** no invented rule and matches current safe boundary.  
**Gap:** WIP asset and production accounting remain unavailable; downstream valuation stays blocked.  
**Classification:** safe deferral, not closure.

## 4. Comparison matrix

| Dimension | A — Material only | B — Standard | C — Actual | D — Standard + variance | E — Undefined |
|---|---|---|---|---|---|
| Cost available during production | Material only | Yes | Partial/late | Yes, provisional | No |
| Uses current valued ITS evidence | Yes | Indirect | Yes | Yes for variance | Evidence only |
| Uses immutable MO standard | No | Yes | No | Yes | No |
| Includes labor/OH/subcon in WIP | No | By allocation | As available | Standard then actual variance | No |
| Requires stage allocation | No | Yes | Yes/equivalent units | Yes | No |
| Handles late actual cost | Deferred | Variance later | Recalculation needed | Designed for reconciliation | Blocked |
| Partial/rework complexity | Medium | High | Highest | High | Deferred |
| Posting readiness today | No | No | No | No | Safely blocked |
| Historical backfill | No | No | No | No | No |
| Alignment with BR-100 | Partial | High | Partial | Highest | Neutral |
| Dependency burden | Medium | High | Highest | High but explicit | Defers all |

## 5. Recommendation

```text
Recommendation:
D — PROVISIONAL STANDARD WIP + ACTUAL VARIANCE RECONCILIATION

Rationale:
- an immutable MO standard-cost snapshot already exists before release;
- actual material/labor/OH/subcon evidence develops over time and is incomplete;
- a provisional standard avoids waiting until MO close for every WIP value;
- later actual reconciliation preserves BR-009/100 variance reporting;
- explicit stage allocation and fail-closed controls prevent invented stage values.

Required dependent decisions before implementation:
- D-07 FG valuation/transfer basis;
- D-09 cost-per-PCS denominator;
- D-08 shipment valuation;
- D-10 COGS;
- D-11 timing/reversal;
- separate approved stage-allocation profile and rework/scrap treatment.

Historical consequence:
No historical WIP value is backfilled. The rule applies prospectively only after an
implementation cutover is separately approved.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — MATERIAL-ONLY WIP
B — STANDARD-COST WIP
C — ACCUMULATED ACTUAL WIP
D — PROVISIONAL STANDARD WIP + ACTUAL VARIANCE
E — KEEP UNDEFINED / BLOCK WIP POSTING
```

## 7. Impact and dependencies

### Impacted modules

Inventory/ITS; Material Issue/Return; Production Order; Cutting/Bundle; Shop Floor scans/WIP transfer; QC/Rework; Subcon; Standard/Actual Cost; FG/Packing; GL/account mapping; period close; reporting.

### Implementation consequence

No implementation is authorized. Depending on the choice, a later phase may require valuation layers, stage allocation profiles, accounting events, deterministic posting keys, cost snapshots, variance journals, reversal/adjustment, and tests.

### Historical-data consequence

No historical WIP, scan, transfer, material ledger, cost, or journal row is created, changed, or backfilled by this analysis.

### Dependency state

```text
D-05 = LOCKED
D-06 = PENDING BUSINESS DECISION
        ↓
D-07 FG Valuation = NEXT only after D-06 is LOCKED
```

## 8. Decision record template

```text
D-06 — WIP Valuation

Status:
PENDING BUSINESS DECISION

Candidates:
A. MATERIAL_ONLY_WIP
B. STANDARD_COST_WIP
C. ACCUMULATED_ACTUAL_WIP
D. PROVISIONAL_STANDARD_WIP_PLUS_ACTUAL_VARIANCE
E. KEEP_UNDEFINED_BLOCK_WIP_POSTING

Recommendation:
D — PROVISIONAL STANDARD WIP + ACTUAL VARIANCE

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-06 remains **PENDING BUSINESS DECISION** and D-07 must not be closed.