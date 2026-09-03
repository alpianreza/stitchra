# STITCHRA — D-10 COGS AUTHORITY

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `f7bcfafabfe265a080c98ace624983fcc752f721`  
> **Dependency:** D-08 is LOCKED to prevailing FG Moving Average at Shipment.  
> **Boundary:** No code, migration, journal posting, backfill, historical rewrite, or production behavior change is authorized.

## 1. Decision question

What amount and recognition event must create company-owned FG Cost of Goods Sold, and how must later actual-cost variance affect COGS?

D-10 defines COGS authority. D-11 will separately define reversal, late-period, and close mechanics.

## 2. Evidence separation

### 2.1 Existing behavior

- Shipment from an eligible Packing List creates an ITS `SHIPMENT` FG quantity-out movement and changes Shipment/Packing/SO statuses.
- Current Shipment code does not directly create a COGS journal.
- `ValuationBoundaryService` exposes `SHIPMENT_COGS` as the candidate accounting event but returns amount `null`, status `BLOCKED`, and Shipment date as a source-date candidate.
- `OperationalPostingService` likewise blocks COGS because the authoritative amount is undefined.
- `GlPostingService` can post registered events using configured debit/credit mapping, amount > 0, source document, journal date, period, and an idempotency key based on company/event/source type/source ID.
- Period close requires an OPEN period and controlled checklist; late operational transaction treatment remains undefined.
- D-08 now locks Shipment inventory cost removal to prevailing company-owned FG Moving Average.
- D-07 and D-09 lock provisional FG standard with later actual variance and FG received as the official actual cost denominator.

### 2.2 Existing Business Rules and locked decisions

- **BR-001:** buyer-owned stock is excluded from company inventory valuation.
- **BR-005:** inventory cost flow uses Moving Average.
- **BR-013:** ITS/ledger is append-only.
- **BR-017:** corrections use approved adjustment.
- **BR-083/PF-10:** Shipment is the FG-out/COGS boundary.
- **BR-101/103:** account mapping, GL journal, period, and close controls are authoritative.
- **BR-105 / D-07:** FG is provisional standard plus actual variance.
- **BR-106 / D-09:** company-owned FG received quantity is the official actual-cost denominator.
- **BR-107 / D-08:** Shipment consumes prevailing FG Moving Average; this does not by itself authorize a COGS journal.

No locked rule currently specifies the COGS amount, exact recognition date, or treatment of post-shipment actual variance.

### 2.3 Technical implementation

Current operational chain:

```text
Packing APPROVED
→ valued FG state / PRODUCTION_RECEIPT
→ Shipment SHIPPED
→ ITS SHIPMENT quantity out
→ COGS journal BLOCKED
```

Available amount after D-08 implementation:

```text
Base Shipment cost
= sum(company-owned ITS SHIPMENT ledger total_cost)
```

Available journal mechanism:

```text
Event: SHIPMENT_COGS
Source: shipments + shipment.id
Date candidate: shipments.ship_date
Period candidate: YYYY-MM(ship_date)
Debit/Credit: configured AccountMapping
Idempotency: company + event + source type + source ID
```

That mechanism is not authorization until Business Owner locks the amount and timing policy.

### 2.4 Conflict/gap

1. Shipment is operationally complete while COGS remains unposted.
2. D-08 gives a provisional/prevailing inventory cost, but final Actual Cost can arrive later.
3. No rule splits later MO variance between FG on hand and already shipped quantities.
4. Recognizing COGS at invoice date would decouple inventory outflow from Shipment.
5. Waiting for MO close can leave shipped goods without COGS in the shipment period.
6. Existing GL idempotency allows one `SHIPMENT_COGS` journal per Shipment; later variance needs a separate controlled event/source or reversal policy.
7. Shipment cancellation, return, correction, closed period, and late-cost handling remain D-11.
8. Buyer-owned FG must not generate company inventory COGS.
9. Historical shipped rows may lack valuation and must not be auto-posted or backfilled.

## 3. Candidate policies

### Candidate A — SHIPMENT-DATE COGS FROM VALUED ITS SHIPMENT + LATER VARIANCE

Policy:

- On Shipment, base COGS equals the sum of valued company-owned ITS `SHIPMENT` ledger `total_cost`.
- Recognition date is `shipments.ship_date`; period is that date's OPEN GL period.
- Debit COGS and credit FG Inventory through configured `SHIPMENT_COGS` mapping.
- Later actual MO variance attributable to already shipped units posts through a separate append-only variance event under D-11; original Shipment journal is not edited.

**Strength:** matches physical inventory outflow, BR-083, D-08 Moving Average, and D-07 variance convergence.  
**Gap:** requires D-11 to lock late variance, closed-period, reversal, cancellation, and return handling.  
**Classification:** recommended.

### Candidate B — SHIPMENT-DATE COGS, NO LATER ACTUAL VARIANCE

Policy:

- Base COGS posts from Shipment Moving Average and is never adjusted to actual MO cost.

**Strength:** simple and final at Shipment.  
**Gap:** breaks D-07 actual variance convergence for units already shipped; variance would remain outside COGS.  
**Classification:** inconsistent with locked upstream policy.

### Candidate C — RECOGNIZE COGS ONLY AFTER ACTUAL MO CLOSE

Policy:

- Shipment removes quantity, but COGS waits until actual MO cost and denominator are final.

**Strength:** final actual cost is used directly.  
**Gap:** delays expense recognition, can mismatch revenue/shipment period, and leaves operationally shipped inventory without COGS.  
**Classification:** accurate late but operationally/accountingly weak.

### Candidate D — AR-INVOICE-DATE COGS

Policy:

- COGS is recognized with or after AR invoice rather than Shipment.

**Strength:** aligns expense with invoiced revenue when invoices are authoritative.  
**Gap:** BR-083 identifies Shipment as boundary; invoice may precede/follow physical outflow or be consolidated/split.  
**Classification:** conflicts with existing operational boundary unless BR-083 is amended.

### Candidate E — KEEP COGS UNDEFINED / BLOCK POSTING

Policy:

- Continue Shipment quantity only; do not post COGS.

**Strength:** matches current safe implementation.  
**Gap:** no inventory relief journal or COGS; end-to-end finance remains blocked.  
**Classification:** safe deferral, not closure.

## 4. Comparison matrix

| Dimension | A — Shipment + variance | B — Shipment final | C — MO close | D — AR invoice | E — Undefined |
|---|---|---|---|---|---|
| Recognition aligned to FG outflow | Highest | Highest | Delayed | Variable | No |
| Uses D-08 Moving Average | Yes | Yes | Indirect | Indirect | No |
| Converges D-07 to actual | Yes, later variance | No | Yes at close | Possible but unclear | No |
| Period matching | Shipment period + controlled variance | Shipment period | Close period | Invoice period | None |
| Supports operational Shipment | Yes | Yes | Quantity only until close | Yes | Quantity only |
| Requires D-11 | High | Medium | High | High | Deferred |
| Existing event/mapping fit | Highest | High | New timing logic | BR-083 conflict | Blocked |
| Historical backfill | No | No | No | No | No |

## 5. Recommendation

```text
Recommendation:
A — SHIPMENT-DATE COGS FROM VALUED ITS SHIPMENT + LATER ACTUAL VARIANCE

Base amount:
Sum of company-owned ITS SHIPMENT ledger total_cost, valued under BR-107.

Recognition:
shipments.ship_date in its matching OPEN GL period.

Journal:
Debit configured COGS account; credit configured FG Inventory account through
SHIPMENT_COGS, with one deterministic posting per Shipment.

Fail closed:
Missing/duplicate ITS Shipment, unvalued ledger line, buyer-owned stock, zero or
invalid amount, invalid source lineage, missing account mapping, non-matching or
closed period, or idempotency conflict.

Later variance:
Actual MO variance attributable to shipped units posts separately and append-only
under D-11; original Shipment journal and ledger are not edited.

Historical consequence:
No historical Shipment receives an automatic COGS journal or revaluation.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — SHIPMENT-DATE COGS + LATER ACTUAL VARIANCE
B — SHIPMENT-DATE COGS, NO LATER VARIANCE
C — COGS ONLY AFTER ACTUAL MO CLOSE
D — AR-INVOICE-DATE COGS
E — KEEP UNDEFINED / BLOCK COGS POSTING
```

## 7. Impact and dependencies

### Impacted modules

Shipment; ITS FG ledger/balance; FG valuation; Actual Cost variance; COGS; GL/account mapping; AR timing; period close; reversal/cancellation/return; reporting.

### Implementation consequence

No implementation is authorized. A later phase may require valued Shipment guards, `SHIPMENT_COGS` posting, deterministic source keys, variance allocation/event, period validation, reversal/cancellation/return handling, and tests.

### Historical-data consequence

No historical Shipment, stock ledger, FG balance, cost, or journal is changed, posted, revalued, or backfilled by this analysis.

### Dependency state

```text
D-08 = LOCKED
D-10 = PENDING BUSINESS DECISION
        ↓
D-11 Reversal/Timing = NEXT only after D-10 is LOCKED
```

## 8. Decision record template

```text
D-10 — COGS Authority

Status:
PENDING BUSINESS DECISION

Candidates:
A. SHIPMENT_DATE_COGS_PLUS_LATER_ACTUAL_VARIANCE
B. SHIPMENT_DATE_COGS_NO_LATER_VARIANCE
C. COGS_ONLY_AFTER_ACTUAL_MO_CLOSE
D. AR_INVOICE_DATE_COGS
E. KEEP_UNDEFINED_BLOCK_COGS_POSTING

Recommendation:
A — SHIPMENT-DATE COGS + LATER ACTUAL VARIANCE

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-10 remains **PENDING BUSINESS DECISION** and D-11 must not be closed.