# STITCHRA — D-03 WHOLE-MO PRODUCTION OUTPUT AUTHORITY

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `b693fafe320368e09b5339609bb56b6b030a4d75`  
> **Dependency:** D-01 is LOCKED to `LAY_ROLL`; D-04 and D-02 remain downstream.  
> **Boundary:** No source code, migration, behavior, backfill, historical rewrite, or legacy-path removal is authorized by this analysis.

## 1. Decision question

Does Stitchra have one authoritative whole-MO `produced quantity`; if yes, which persisted event owns it? If no single event can safely represent every production stage, should Stitchra retain separate named stage measures and prohibit a generic authoritative `qty_produced`?

## 2. Evidence separation

### 2.1 Existing behavior

- `production_orders.qty_produced` exists, defaults to zero, and remains fillable.
- No inspected Production, Cutting, Shop Floor, QC, or Packing service provides an authoritative writer or endpoint for `qty_produced`.
- Cut Output persists quantity per Lay and Cut Order Line.
- Bundle quantity is derived from Cut Output for the new path; legacy Bundles may lack Cut Output.
- Sewing and Finishing OUT scans persist full-Bundle quantity. Duplicate same stage/operation/direction is blocked.
- A completed Sewing route moves Bundle WIP to Finishing.
- Finishing OUT is persisted, but there is no mandatory terminal Finishing-operation set or one explicit Finished completion event.
- QC FINAL persists an inspection `lot_qty` and verdict. A PASS makes the MO eligible for Packing; reinspection cycles may exist.
- Packing aggregates Carton quantities under QC FINAL PASS controls.
- Packing finalize posts ITS `PRODUCTION_RECEIPT`, which is authoritative for FG stock quantity created from that Packing List.
- MO status transitions are stage progress markers. No authoritative MO completion endpoint/event was found; `actual_end` is not written by the inspected services.
- Packing still uses legacy `qty_produced` as an extra ceiling and to decide the `PACKED` transition.
- Backflush requires legacy `qty_produced > 0`.
- Actual Cost prefers final-routing-operation OUT scan evidence and falls back to legacy `qty_produced`; cost-per-unit remains undefined.

### 2.2 Existing Business Rules

- **BR-007:** Sewing output is at least per line per day; data structure supports per operator/hour/bundle scans.
- **BR-009:** Actual cost is per MO; labor and overhead use `output`, but the rule does not select one cross-stage source for whole-MO output.
- **BR-017/063:** Historical/output corrections require approved adjustment; no direct edit.
- **BR-064:** WIP moves through WIP transfer, not direct quantity edits.
- **BR-080:** Only QC PASS pieces may enter Carton. This defines Packing eligibility, not whole-MO output authority.
- **BR-083/PF-09:** `PRODUCTION_RECEIPT` establishes FG quantity and Shipment later reduces FG. This does not state that FG receipt is the universal production-output measure.
- **PF-05:** Cut Output is quantity from Lay and becomes Bundle source.
- **PF-06:** Sewing and Finishing have stage outputs; the process description distinguishes SEWN and FINISHED stages.
- **PF-07:** QC FINAL PASS defines accepted Packing eligibility.
- **BR-120:** Traceability requires stage-to-stage lineage, which supports distinct persisted measures.

No locked rule selects Cut Output, Sewing OUT, Finishing OUT, QC FINAL PASS, Packing, FG receipt, or `qty_produced` as one universal whole-MO output.

### 2.3 Technical implementation

`ProductionOutputAuthorityService` deliberately reports:

```text
production_output_authority.status = NOT DEFINED
authoritative_source = null
authoritative_qty = null
qty_produced = LEGACY COMPATIBILITY FALLBACK — NOT AUTHORITATIVE
production_completion = NOT DEFINED
```

The same read-only service exposes stage evidence:

```text
MO
→ Cut Output
→ Bundle
→ Sewing final OUT
→ Sewing→Finishing WIP
→ Finishing OUT evidence
→ QC FINAL
→ Packing
→ ITS PRODUCTION_RECEIPT
→ FG
```

`ActualCostingService` currently treats final-routing-operation OUT scans as the strongest available labor/OH output evidence when one unambiguous OUT exists per Bundle. That is existing technical behavior, not a locked whole-MO Business Rule.

### 2.4 Conflict/gap

- The term `output` is used for different stage facts.
- `qty_produced` is consumed by Backflush and Packing but has no authoritative writer.
- Cut Output measures cut pieces before Sewing/Finishing/QC losses.
- Sewing OUT measures sewn WIP, not necessarily finished or accepted goods.
- Finishing OUT has no explicit terminal completion definition.
- QC `lot_qty` is an inspection population and can recur across cycles; it is not a production writer.
- Packing quantity is constrained accepted output but direct Bundle/Finishing→Carton allocation remains undefined.
- `PRODUCTION_RECEIPT` is authoritative FG quantity, but not necessarily operational Sewing/Finishing output.
- Defect, rework, reject, second-grade, scrap, and partial-production arithmetic is not defined as one MO reconciliation equation.
- Therefore one universal quantity can silently collapse distinct operational meanings.

## 3. Candidate analysis

### Candidate A — CUT_OUTPUT

**Meaning:** sum of persisted Cut Outputs on completed Lays is whole-MO produced quantity.

- Evidence: exact Lay/Cut Order Line quantity; Bundle totals are validated for completed Lays.
- Strength: earliest deterministic physical piece output after D-01's Lay Roll authority.
- Gap: occurs before Sewing, Finishing, QC, reject, rework, and packing losses.
- Consequence: overstates finished/accepted/FG output if used universally.
- Compatibility: legacy Bundles may lack Cut Output.
- Classification: **DEFINED as Cutting output; NOT DEFINED as whole-MO production output**.

### Candidate B — FINAL_SEWING_OUT

**Meaning:** unique OUT scan at the final routing operation in Sewing is whole-MO produced quantity.

- Evidence: append-only full-Bundle scans, duplicate guards, routing sequence, WIP transfer.
- Strength: strongest current operational evidence used by labor/OH costing and daily output.
- Gap: represents sewn WIP before Finishing and FINAL QC; final routing operation/stage semantics can be ambiguous if routing includes later processes.
- Consequence: may include pieces later reworked/rejected or not packed.
- Classification: **DEFINED for Sewing output; PARTIAL for whole-MO output**.

### Candidate C — FINAL_FINISHING_OUT

**Meaning:** terminal Finishing OUT is whole-MO produced quantity.

- Evidence: full-Bundle Finishing OUT with valid Sewing WIP source and forward operation order.
- Strength: closest operational transformation output before QC.
- Gap: no mandatory terminal Finishing operation/completion marker is defined; any recorded Finishing OUT is evidence, not necessarily final completion.
- Consequence: requires a Business Rule defining the terminal Finishing event before safe implementation.
- Classification: **PARTIAL / NOT DEFINED terminal event**.

### Candidate D — QC_FINAL_PASS

**Meaning:** the latest FINAL PASS inspection `lot_qty` is whole-MO produced quantity.

- Evidence: BR-080 makes FINAL PASS the Packing eligibility boundary.
- Strength: accepted-quality boundary.
- Gap: `lot_qty` is an inspection lot, not an append-only production-output writer; reinspection cycles can repeat the same population; accepted quantity arithmetic is not persisted separately from lot quantity.
- Consequence: risks double interpretation and does not identify actual production completion.
- Classification: **DEFINED for Packing eligibility; NOT DEFINED as whole-MO output**.

### Candidate E — PACKING / ITS PRODUCTION_RECEIPT

**Meaning:** approved Packing quantity or ITS `PRODUCTION_RECEIPT.qty_in` is whole-MO produced quantity.

- Evidence: Carton quantity is checked against FINAL PASS; Packing finalize creates idempotent FG stock receipt.
- Strength: strongest existing accepted-and-packed/FG quantity authority.
- Gap: it is a downstream FG event, may be partial across Packing Lists, and direct Bundle/Finishing→Carton allocation is undefined. Production output may exist before Packing.
- Consequence: makes whole-MO output equal packed/received FG rather than operational production output.
- Classification: **DEFINED as Packing/FG quantity; PARTIAL as whole-MO output**.

### Candidate F — SEPARATE_NAMED_MEASURES

**Meaning:** no single generic whole-MO quantity is authoritative. Each persisted event is authoritative only for its named stage measure; generic `qty_produced` remains non-authoritative compatibility data.

Examples of existing stage facts, without inventing reconciliation arithmetic:

```text
cut_output_qty
sewing_output_qty
finishing_output_evidence
qc_final_pass_lot_qty
packed_qty
fg_received_qty
shipped_qty
```

- Evidence: current Business Rules and code already define stage-specific events and boundaries.
- Strength: preserves operational meaning and prevents one number from silently serving Cutting, Backflush, Costing, Packing, FG, and Shipment with different semantics.
- Gap: every downstream consumer must explicitly choose its required named measure; D-04 must choose the Backflush basis and D-09 must choose cost-per-unit denominator(s).
- Consequence: no silent writer for generic `qty_produced`; compatibility behavior must be removed or isolated only in a later implementation phase.
- Classification: **EVIDENCE-ALIGNED; requires explicit owner decision**.

## 4. Side-by-side matrix

| Dimension | Cut Output | Final Sewing OUT | Final Finishing OUT | QC FINAL PASS | Packing/FG Receipt | Separate Named Measures |
|---|---|---|---|---|---|---|
| Existing persisted writer | DEFINED | DEFINED | DEFINED | DEFINED inspection | DEFINED | Uses existing writers |
| Explicit whole-MO Business Rule | NOT DEFINED | NOT DEFINED | NOT DEFINED | NOT DEFINED | NOT DEFINED | NOT DEFINED until selected |
| Stage meaning | Cutting | Sewing | Finishing evidence | Quality eligibility | Packed/FG | Explicit per stage |
| Append-only event | PARTIAL | EVIDENCE | EVIDENCE | PARTIAL/cycles | EVIDENCE via ITS receipt | Depends on source |
| Handles downstream loss | NO | PARTIAL | PARTIAL | Accepted lot only | Accepted/packed | Does not collapse stages |
| Partial production | DEFINED records | Full-Bundle scans | Full-Bundle scans | Lot may be partial | Multiple Packing Lists possible | Preserves partial facts |
| Rework/reject arithmetic | NOT DEFINED | NOT DEFINED | NOT DEFINED | PARTIAL via NCR | Only packed accepted qty | Requires separate policy |
| Traceability | Lay→Bundle | Bundle→scan/WIP | WIP→scan | MO/inspection | QC→Packing→FG | Full stage chain |
| Current Actual Cost use | Not used for labor/OH | Strongest scan evidence | Possible ambiguous final-op evidence | Not used | Not used for labor/OH | Consumer must select |
| Current Backflush use | None | None | None | None | None | None; D-04 required |
| Current Packing use | Indirect Bundle only | No direct link | No direct link | Eligibility | Direct | Named packing/FG facts |
| Historical compatibility | Legacy gaps | Existing scans | Existing scans | Existing cycles | Existing receipts | Highest preservation |
| Risk as universal number | High | Medium/high | Medium | High | Medium | Lowest semantic conflation |

## 5. Recommendation

```text
Recommendation:
SEPARATE_NAMED_MEASURES

Primary evidence:
The repository has multiple persisted, auditable stage quantities, but no locked
Business Rule or authoritative writer for one universal qty_produced. Each source
has a different operational meaning and lifecycle.

Secondary evidence:
BR-007, PF-05, PF-06, BR-080, and PF-09 define Cutting, Sewing, Finishing,
QC eligibility, Packing, and FG boundaries separately. ProductionOutputAuthorityService
also intentionally exposes these sources without fabricating one authority.

Main risk:
Downstream consumers can no longer rely on a generic qty_produced. D-04 must choose
Backflush basis, D-09 must choose cost-per-unit denominator(s), and Packing must
remove its legacy generic ceiling only after approved implementation.

Historical risk:
Existing qty_produced values and stage evidence cannot be rewritten or reinterpreted.
They remain compatibility data until a later historical policy is approved.

Implementation consequence:
Only after lock, retain authoritative named stage measures, keep generic qty_produced
non-authoritative, and require each consumer to name its source. Do not create a
new aggregate writer or migration during Business Rule Review.
```

The recommendation is based on evidence and semantic separation, not implementation preference. It is not the decision.

## 6. Decision options for Business Owner

```text
A — FINAL SEWING OUT
B — FINAL FINISHING OUT
C — QC FINAL PASS
D — PACKING / ITS PRODUCTION_RECEIPT
E — SEPARATE NAMED MEASURES (no single authoritative qty_produced)
F — CUT OUTPUT / another explicitly stated source
```

## 7. Consequences and dependencies

### Impacted modules

Production Order, Cutting/Cut Output/Bundle, Shop Floor scans/WIP, Finishing, QC/NCR, Packing/Carton, ITS FG receipt, Actual Cost, Backflush, dashboards/reporting, and governance.

### Historical consequence

No historical `qty_produced`, scan, Cut Output, QC inspection, Packing, or ITS ledger data may be rewritten by this decision analysis. Compatibility/cutover policy is not selected here.

### Dependency chain

```text
D-01 LAY_ROLL — LOCKED
        ↓
D-03 Whole-MO Output — PENDING
        ↓
D-04 ACTUAL vs BACKFLUSH — BLOCKED pending D-03
        ↓
D-02 Historical Marker/Lay Policy — follows D-04
```

## 8. Decision record template

```text
D-03 — Whole-MO Production Output Authority

Status:
PENDING BUSINESS DECISION

Candidates:
A. FINAL_SEWING_OUT
B. FINAL_FINISHING_OUT
C. QC_FINAL_PASS
D. PACKING_OR_ITS_PRODUCTION_RECEIPT
E. SEPARATE_NAMED_MEASURES
F. OTHER_EXPLICIT_SOURCE

Evidence:
Stage-level quantities are persisted and auditable, but qty_produced has no
authoritative writer and no locked rule selects one universal source.

Recommendation:
SEPARATE_NAMED_MEASURES

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-03 remains **PENDING BUSINESS DECISION**. D-04 and D-02 must not be opened as completed decisions.