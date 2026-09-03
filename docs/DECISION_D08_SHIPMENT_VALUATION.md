# STITCHRA — D-08 SHIPMENT VALUATION

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `fb3934eef79a77e0bddef2f0609e46a2e51ebf87`  
> **Dependency:** D-09 is LOCKED to company-owned ITS FG received quantity as the official cost-per-PCS denominator.  
> **Boundary:** No code, migration, valuation posting, COGS journal, backfill, historical rewrite, or production behavior change is authorized.

## 1. Decision question

Which cost authority must ITS `SHIPMENT` use when company-owned FG leaves inventory, before D-10 decides the accounting recognition of COGS?

Shipment valuation is the inventory cost removed. It is not, by itself, the rule that authorizes a COGS journal.

## 2. Evidence separation

### 2.1 Existing behavior

- Shipment is created only from an APPROVED Packing List with valid same-company/same-MO QC FINAL PASS and a traceable ITS `PRODUCTION_RECEIPT`.
- One Packing List may have only one Shipment.
- Shipment matrix must exactly match the source Packing List/FG receipt matrix.
- Shipment must use the same active FG warehouse as the source `PRODUCTION_RECEIPT`.
- ITS `SHIPMENT` posts FG quantity out after stock-availability checks.
- `ShipmentService` does not explicitly provide `unit_cost` on its shipment lines.
- The stock ledger supports nullable `unit_cost` and `total_cost`; schema capability does not select an authority.
- `ValuationBoundaryService` reports Shipment valuation `NOT DEFINED` and COGS blocked.
- `OperationalPostingService` blocks `SHIPMENT_COGS` because the amount authority is missing.
- D-07 has locked prospective FG at provisional standard plus actual variance.
- D-09 has locked FG received quantity as the official actual-cost denominator.

### 2.2 Existing Business Rules and locked decisions

- **BR-001:** buyer-owned material/stock is excluded from company inventory valuation.
- **BR-005:** inventory valuation uses Moving Average where an authoritative cost source exists.
- **BR-013:** all stock movements use ITS; the ledger is append-only.
- **BR-017:** historical corrections use approved adjustments.
- **BR-080/PF-09:** QC FINAL PASS enables Packing and FG receipt.
- **BR-083/PF-10:** Shipment is the FG-out/COGS boundary, but does not define the amount.
- **BR-105 / D-07:** FG is provisional standard with later actual variance.
- **BR-106 / D-09:** FG received quantity is the official actual-cost denominator.

No locked rule states whether Shipment consumes exact receipt cost, prevailing Moving Average, provisional standard directly, final actual cost, or remains unvalued.

### 2.3 Technical implementation

Current quantity lineage is strong:

```text
QC FINAL PASS
→ Packing List / Carton matrix
→ ITS PRODUCTION_RECEIPT to one FG warehouse
→ Shipment with identical matrix
→ ITS SHIPMENT from that warehouse
```

Current valuation authority stops here:

```text
D-07 provisional FG standard + later actual variance
→ FG stock balance / ledger
→ [shipment cost source NOT LOCKED]
→ ITS SHIPMENT quantity
→ [D-10 COGS NOT LOCKED]
```

The explicit Packing List link proves physical/operational provenance. It does not by itself authorize specific-identification costing if BR-005 Moving Average governs the item balance.

### 2.4 Conflict/gap

1. Shipment quantity may post while FG cost is null or provisional.
2. Exact Packing receipt lineage could suggest specific identification, while BR-005 indicates Moving Average.
3. Multiple MO/Packing receipts for the same style-color-size may coexist in one FG warehouse.
4. D-07 actual variance can arrive after some units have shipped.
5. No rule yet divides late variance between FG on hand and units already shipped.
6. No rule defines Shipment cancellation/reversal valuation or closed-period handling.
7. No authoritative treatment exists for UOM conversion, grade/second-quality stock, or buyer-owned FG exceptions.
8. Current one-Packing-List/one-Shipment design limits partial shipment but does not define cost.
9. Historical Shipment ledger rows must not be revalued automatically.

## 3. Candidate policies

### Candidate A — EXACT SOURCE RECEIPT COST

Policy:

- Shipment consumes the valuation attached to its exact Packing List `PRODUCTION_RECEIPT`.
- Cost uses specific identification by receipt provenance.

**Strength:** strongest document lineage and intuitive MO/Packing trace.  
**Gap:** can conflict with BR-005 Moving Average when identical FG from multiple receipts shares one balance; stock reservation does not maintain a cost layer by Packing List.  
**Classification:** traceable but requires an explicit departure from Moving Average.

### Candidate B — PREVAILING FG MOVING AVERAGE AT SHIPMENT

Policy:

- ITS `SHIPMENT` uses the authoritative company-owned FG Moving Average for the exact style/colorway/size, warehouse, ownership, and UOM at posting time.
- Cost is consumed from the FG valuation state; it is not recalculated from Packing, MO standard, or Actual Cost during Shipment.
- Missing/invalid cost, ownership, UOM, or stock state fails closed.
- Later MO actual variance is allocated between remaining FG and previously shipped units under D-10/D-11 through append-only entries.

**Strength:** aligns with BR-005 and normal inventory cost flow; supports mixed receipts in one warehouse.  
**Gap:** requires D-07 FG valuation to be implemented and D-10/D-11 to allocate late variance.  
**Classification:** recommended.

### Candidate C — DIRECT PROVISIONAL STANDARD AT SHIPMENT

Policy:

- Shipment ignores current FG valuation state and uses the source MO provisional standard directly.
- Later actual variance adjusts shipped units.

**Strength:** stable and source-MO traceable.  
**Gap:** duplicates D-07 logic, can diverge from FG Moving Average after mixed receipts or adjustments, and bypasses inventory valuation state.  
**Classification:** not recommended.

### Candidate D — FINAL ACTUAL COST ONLY

Policy:

- Shipment valuation is permitted only after actual MO unit cost is final.

**Strength:** no provisional shipment value.  
**Gap:** can block operational shipment while cost evidence is incomplete; conflicts with the D-07 provisional-then-variance model.  
**Classification:** accurate only after close, operationally restrictive.

### Candidate E — KEEP SHIPMENT VALUATION UNDEFINED / BLOCK COST POSTING

Policy:

- Continue Shipment quantity movement only.
- Keep Shipment valuation and COGS blocked.

**Strength:** matches current safe boundary.  
**Gap:** no valued FG outflow or COGS.  
**Classification:** safe deferral, not closure.

## 4. Comparison matrix

| Dimension | A — Exact receipt | B — Moving Average | C — Direct standard | D — Final actual only | E — Undefined |
|---|---|---|---|---|---|
| Uses current FG valuation state | Receipt-specific | Yes | No | Eventually | No |
| Aligns BR-005 | Low unless amended | Highest | Low | Depends | Neutral |
| Handles mixed receipts | Requires layers | Yes | Can diverge | Requires final layers | No cost |
| Supports operational shipment before MO close | Yes | Yes | Yes | No | Quantity only |
| Aligns D-07 provisional + variance | Medium | Highest | Duplicates source | Low | Defers |
| Late actual variance | Receipt allocation | D-10/D-11 split | MO-specific adjustment | None after close | Blocked |
| Requires exact Packing lineage | Yes | Eligibility only | Yes | Yes | Quantity only |
| Historical backfill | No | No | No | No | No |
| Control complexity | High | Medium/high | High | High operational impact | Lowest |

## 5. Recommendation

```text
Recommendation:
B — PREVAILING FG MOVING AVERAGE AT SHIPMENT

Authority:
ITS SHIPMENT consumes the company-owned FG valuation state for the exact
style/colorway/size, warehouse, ownership, and UOM at posting time.

Source discipline:
Packing List and PRODUCTION_RECEIPT remain mandatory physical lineage, but they do
not override BR-005 with specific-identification costing.

Fail closed:
Missing unit cost, invalid ownership/UOM, insufficient stock, unresolved valuation
state, or unsupported grade allocation.

Late variance:
D-10/D-11 must allocate post-shipment actual variance between FG on hand and COGS
through append-only entries; original Shipment ledger history is not edited.

Historical consequence:
No historical Shipment or ledger row is backfilled or revalued automatically.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — EXACT SOURCE RECEIPT COST
B — PREVAILING FG MOVING AVERAGE
C — DIRECT PROVISIONAL STANDARD
D — FINAL ACTUAL COST ONLY
E — KEEP UNDEFINED / BLOCK SHIPMENT VALUATION
```

## 7. Impact and dependencies

### Impacted modules

Shipment; Packing/Carton; ITS FG ledger/balance; FG valuation; Actual Cost variance; COGS; GL/account mapping; period close; reversal; reporting.

### Implementation consequence

No implementation is authorized. A later phase may require authoritative FG cost-state reads, valuation guards, ownership/UOM/grade dimensions, deterministic Shipment costing, late-variance allocation, reversal/period controls, and tests.

### Historical-data consequence

No historical Shipment, stock ledger, balance, receipt, cost, or journal row is changed or backfilled by this analysis.

### Dependency state

```text
D-09 = LOCKED
D-08 = PENDING BUSINESS DECISION
        ↓
D-10 COGS = NEXT only after D-08 is LOCKED
```

## 8. Decision record template

```text
D-08 — Shipment Valuation

Status:
PENDING BUSINESS DECISION

Candidates:
A. EXACT_SOURCE_RECEIPT_COST
B. PREVAILING_FG_MOVING_AVERAGE
C. DIRECT_PROVISIONAL_STANDARD
D. FINAL_ACTUAL_COST_ONLY
E. KEEP_UNDEFINED_BLOCK_SHIPMENT_VALUATION

Recommendation:
B — PREVAILING FG MOVING AVERAGE

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-08 remains **PENDING BUSINESS DECISION** and D-10 must not be closed.