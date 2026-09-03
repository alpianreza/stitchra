# STITCHRA — D-04 ACTUAL VS BACKFLUSH SEMANTICS

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `8ba3134941e42dbebcaaa71c25b0802258d2e56f`  
> **Dependencies:** D-01 `LAY_ROLL` and D-03 `SEPARATE_NAMED_MEASURES` are LOCKED.  
> **Boundary:** No code, migration, backfill, historical rewrite, production behavior change, or legacy endpoint removal is authorized.

## 1. Decision question

How must `ACTUAL` Material Issue and `BACKFLUSH` coexist for one MO/material, and which locked named production measure may trigger cumulative Backflush?

D-04 must distinguish three meanings:

```text
Inventory issue timing
Actual physical consumption evidence
Formula-based Backflush consumption
```

They are not automatically the same event.

## 2. Evidence separation

### 2.1 Existing behavior

- A manual Material Issue creates `material_issues.mode = ACTUAL` and posts ITS `MATERIAL_ISSUE`.
- Fabric is issued per Roll from a hard reservation; the issue creates/updates the MO/Roll dispatch balance.
- D-01 separately locks Lay Roll as the new-execution Actual Fabric Consumption authority. Therefore an ACTUAL Material Issue is inventory dispatch/issue evidence, not the sole physical-consumption fact for fabric.
- The Backflush endpoint creates `material_issues.mode = BACKFLUSH` and also posts ITS `MATERIAL_ISSUE`.
- Backflush loops approved BOM lines marked `is_backflush`, computes `grossPerPcs × production_orders.qty_produced`, subtracts only previously posted BACKFLUSH quantity, and posts the remaining delta.
- Backflush accepts MO statuses RELEASED, CUTTING, SEWING, and FINISHING.
- `production_orders.qty_produced` has no authoritative writer and is locked non-authoritative by D-03.
- The code does not subtract ACTUAL issue quantity for the same material from the Backflush target.
- Operational integrity inspection can report ACTUAL+BACKFLUSH overlap per material, but the posting service does not block it.
- ITS remains the only stock-movement authority and prevents duplicate movement for the same movement type/source document.
- Re-running Backflush creates a new Material Issue source document; the service uses cumulative prior BACKFLUSH lines to calculate the delta while locking the MO.

### 2.2 Existing Business Rules

- **BR-013:** every stock change must use ITS; ledger is append-only.
- **BR-017:** historical corrections require an approved adjustment, not direct edits.
- **BR-041:** fabric uses actual issue per Roll from Lay; cheap trims may Backflush from `output × BOM`, configurable per material class.
- **BR-060:** MO release creates hard reservations.
- **D-01 / clarified BR-031:** Lay Roll is sole Actual Fabric Consumption authority for new execution; Marker is not a competing writer.
- **D-03 / BR-065:** no generic whole-MO `qty_produced` is authoritative; each downstream consumer must explicitly name the stage measure it consumes.

BR-041 authorizes a hybrid capability, but it does not define:

- ACTUAL/BACKFLUSH precedence for the same material;
- whether the modes are mutually exclusive;
- the named output stage for Backflush;
- trigger timing;
- reversal/recalculation behavior;
- UOM conversion policy between BOM, reservation, and stock;
- how material-class configuration is snapshotted for an MO.

### 2.3 Technical implementation

The persisted schema supports two Material Issue header modes:

```text
material_issues.mode = ACTUAL | BACKFLUSH
```

The BOM line has:

```text
is_backflush
qty_per_pcs
consumption_estimated
wastage_pct
shrinkage_pct
uom_id
```

`grossPerPcs()` uses estimated consumption or `qty_per_pcs`, including wastage and shrinkage. Backflush therefore posts a formula quantity; it does not observe physical trim consumption.

Current delta logic is effectively:

```text
backflush_target = gross_per_pcs × legacy_qty_produced
backflush_delta  = backflush_target − prior_backflush_issue_qty
```

It is not:

```text
backflush_target − ACTUAL_issue_qty − prior_backflush_issue_qty
```

ITS idempotency is per generated Material Issue source document, not one permanent MO/material Backflush key. The MO row lock protects the current service call, but the semantic source remains undefined.

### 2.4 Conflict/gap

1. **Double-issue risk:** the same material can have ACTUAL and BACKFLUSH issue quantities because only previous Backflush is netted.
2. **Invalid source:** Backflush reads non-authoritative legacy `qty_produced`, conflicting with D-03.
3. **Early trigger:** Backflush may run while MO is merely RELEASED, before any locked stage output exists.
4. **Meaning collision:** for fabric, inventory issue and Lay Roll physical consumption are different authorities.
5. **Class/line ambiguity:** BR-041 says configurable by material class; implementation stores `is_backflush` on BOM lines and MO allocations.
6. **UOM ambiguity:** Backflush arithmetic uses BOM-line UOM while reservation quantities are consumed directly; no explicit conversion is applied in the inspected Backflush path.
7. **Reversal ambiguity:** no locked rule states how reduced/corrected output changes an append-only Backflush issue.
8. **Completion ambiguity:** D-03 locks separate named measures, so Backflush cannot silently use a universal completion quantity.

## 3. Candidate policies

### Candidate A — EXCLUSIVE PER MATERIAL + REQUIRED NAMED STAGE

```text
Fabric Roll-tracked:
ACTUAL issue/dispatch + LAY_ROLL physical consumption; BACKFLUSH prohibited.

Eligible non-fabric material:
exactly one method per MO/material: ACTUAL or BACKFLUSH.

BACKFLUSH:
requires an explicitly configured named stage measure; missing configuration fails closed.
Cumulative target = locked BOM basis × authoritative named-stage quantity.
Delta = cumulative target − prior BACKFLUSH postings.
```

- Aligns with BR-041's hybrid intent and D-03's named-measure rule.
- Blocks ACTUAL/BACKFLUSH overlap for the same MO/material.
- Allows different material classes to consume at different production stages without inventing one universal output.
- Requires a later implementation design for configuration snapshot, UOM conversion, event timing, and approved reversal.
- Historical rows remain unchanged and may be classified as legacy/conflict evidence.

**Classification:** strongest evidence alignment; requires Business Owner approval.

### Candidate B — EXCLUSIVE + GLOBAL FINAL SEWING OUT

```text
Fabric = ACTUAL/LAY_ROLL only.
Backflush-eligible trim = cumulative Final Sewing OUT × locked BOM basis.
ACTUAL and BACKFLUSH are mutually exclusive per MO/material.
```

- Uses the strongest existing operational output evidence and BR-007.
- Simpler than per-class stage configuration.
- May post materials consumed in Finishing/Packing too early or against the wrong stage.
- Assumes one global stage not stated by BR-041.

**Classification:** technically plausible; business scope may be too broad.

### Candidate C — EXCLUSIVE + GLOBAL FG PRODUCTION RECEIPT

```text
Fabric = ACTUAL/LAY_ROLL only.
Backflush-eligible trim = cumulative ITS PRODUCTION_RECEIPT × locked BOM basis.
ACTUAL and BACKFLUSH are mutually exclusive per MO/material.
```

- Uses a defined, append-only FG quantity authority.
- Naturally supports partial Packing Lists/receipts.
- Posts consumption late and equates production consumption with FG receipt.
- Materials consumed in Sewing/Finishing remain in RM inventory until Packing finalizes.

**Classification:** strong event authority; weak operational timing fit.

### Candidate D — ALL ACTUAL, NO BACKFLUSH

- Every material is issued through ACTUAL Material Issue; fabric physical consumption still follows D-01 Lay Roll.
- Eliminates formula output dependency and ACTUAL/BACKFLUSH overlap.
- Conflicts with BR-041's permitted configurable cheap-trim Backflush unless BR-041 is amended to remove that capability.
- Increases operational transaction burden for low-value trims.

**Classification:** safe but requires explicit BR-041 policy change.

### Candidate E — KEEP UNDEFINED / BLOCK AUTHORITATIVE BACKFLUSH

- Preserve legacy endpoint/data for readability.
- Do not treat new Backflush execution as authoritative until semantics are chosen.
- No rule is invented, but D-02 and later implementation remain blocked.

**Classification:** safest deferral; no closure.

### Explicit alternatives not recommended as default candidates

- **Global Final Finishing OUT:** terminal Finishing event is not yet mandatory/defined.
- **Global QC FINAL PASS lot quantity:** an inspection lot can repeat across cycles and is not a consumption writer.
- **Net ACTUAL against BACKFLUSH without exclusivity:** changes semantics into a settlement/reconciliation mechanism not stated by BR-041 and requires correction rules.

## 4. Comparison matrix

| Dimension | A — Per-material named stage | B — Final Sewing | C — FG Receipt | D — All Actual | E — Undefined |
|---|---|---|---|---|---|
| Aligns BR-041 hybrid intent | Highest | Partial | Partial | No; amendment needed | Deferred |
| Aligns D-03 named measures | Yes | Yes | Yes | Not needed | Deferred |
| Blocks ACTUAL/BACKFLUSH overlap | Yes | Yes | Yes | Yes | New authority blocked |
| Fabric follows D-01 | Yes | Yes | Yes | Yes | Yes |
| Stage timing fit | Configurable/explicit | Sewing only | Late FG boundary | Manual issue timing | None |
| Partial production | By chosen cumulative measure | Supported | Supported | Manual | Undefined |
| Existing schema sufficient | No | Mostly no | Mostly no | Behavior change only | Yes/preserve |
| UOM rule required | Yes | Yes | Yes | Existing ACTUAL rules | Still unresolved |
| Reversal rule required | Yes | Yes | Yes | Adjustment rules | Still unresolved |
| Historical rewrite | No | No | No | No | No |
| Risk of semantic conflation | Lowest | Medium | Medium/high | Low | Deferred |

## 5. Recommendation

```text
Recommendation:
A — EXCLUSIVE PER MATERIAL + REQUIRED NAMED STAGE

Why:
- preserves BR-041's approved hybrid capability;
- obeys D-01 for fabric and D-03 for named output measures;
- explicitly prevents ACTUAL/BACKFLUSH double issue;
- avoids choosing one universal stage for every trim class without evidence;
- fails closed when source-stage/UOM configuration is absent.

Required follow-on design after lock, not in this review phase:
- material-class or snapshotted MO/material method;
- explicit named-stage source;
- canonical UOM and conversion;
- cumulative delta/idempotency key;
- approved reversal/adjustment for corrected output;
- prevention of ACTUAL/BACKFLUSH overlap.

Historical consequence:
Existing ACTUAL, BACKFLUSH, and overlapping rows remain unchanged and are classified
as historical/legacy evidence. No reconciliation or rewrite is authorized.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — EXCLUSIVE PER MATERIAL + REQUIRED NAMED STAGE
B — EXCLUSIVE + GLOBAL FINAL SEWING OUT
C — EXCLUSIVE + GLOBAL FG PRODUCTION RECEIPT
D — ALL ACTUAL / NO BACKFLUSH
E — KEEP UNDEFINED / BLOCK AUTHORITATIVE BACKFLUSH
```

## 7. Impact and dependencies

### Impacted modules

BOM/Material master; MO release/reservation; Material Issue; Lay Roll/dispatch; ITS; Shop Floor output; Packing/FG receipt; Actual Cost; audit/approval; reporting.

### Implementation consequence

No implementation is authorized now. Any later change may require schema/configuration, service guards, UOM conversion, source-event linkage, idempotency, and adjustment workflow. These require a separately authorized implementation phase.

### Historical-data consequence

No historical Material Issue, ledger, reservation, allocation, Lay Roll, or output row may be changed. Existing overlap is evidence for D-02/history handling, not permission to reconcile automatically.

### Dependency chain

```text
D-01 LAY_ROLL — LOCKED
D-03 SEPARATE_NAMED_MEASURES — LOCKED
        ↓
D-04 ACTUAL vs BACKFLUSH — PENDING
        ↓
D-02 Historical Marker/Lay Mixed Path Policy — BLOCKED pending D-04
```

## 8. Decision record template

```text
D-04 — ACTUAL vs BACKFLUSH Semantics

Status:
PENDING BUSINESS DECISION

Candidates:
A. EXCLUSIVE_PER_MATERIAL_REQUIRED_NAMED_STAGE
B. EXCLUSIVE_GLOBAL_FINAL_SEWING_OUT
C. EXCLUSIVE_GLOBAL_FG_PRODUCTION_RECEIPT
D. ALL_ACTUAL_NO_BACKFLUSH
E. KEEP_UNDEFINED_BLOCK_AUTHORITATIVE_BACKFLUSH

Recommendation:
A — EXCLUSIVE PER MATERIAL + REQUIRED NAMED STAGE

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-04 remains **PENDING BUSINESS DECISION** and D-02 must not be closed.