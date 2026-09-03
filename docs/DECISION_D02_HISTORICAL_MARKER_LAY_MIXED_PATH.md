# STITCHRA — D-02 HISTORICAL MARKER/LAY MIXED PATH POLICY

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `28cdb225044d8f4bf697fd6bfbfbad42e3cd10e3`  
> **Dependencies:** D-01 `LAY_ROLL`, D-03 `SEPARATE_NAMED_MEASURES`, and D-04 `EXCLUSIVE_PER_MATERIAL_REQUIRED_NAMED_STAGE` are LOCKED.  
> **Boundary:** No migration, backfill, source rewrite, production behavior change, or legacy endpoint removal is authorized.

## 1. Decision question

How must Stitchra classify and handle historical records created through Marker, Lay Roll, or both, when lineage is incomplete and the same dispatch/physical controls may already contain combined effects?

This decision is about **historical interpretation and mutation policy**. It does not change D-01's rule for new execution.

## 2. Evidence separation

### 2.1 Existing behavior

- Marker Logs persist Cut Order, Fabric Roll, marker length, plies, measured quantity, UOM-compatible quantities, efficiency, creator, and timestamps.
- Lay Roll persists Lay, Fabric Roll, exact use-UOM quantity, override state, creator, and timestamps.
- Both paths increment the same MO/Roll `fabric_dispatch_balances.qty_consumed` and decrement the same Fabric Roll physical remaining quantity.
- Marker completion increments MO material-allocation consumption for that Cut Order.
- Lay completion sets MO material-allocation consumption from the aggregate Lay Roll usage for the MO.
- New Marker-after-Lay and Lay-after-Marker attempts are blocked at service boundaries.
- If Marker and Lay Roll already coexist historically on one MO, Cut Order completion mutation is blocked rather than reconciled by assumption.
- Historical rows remain readable through operational-integrity evidence.
- Legacy Bundles may have `cut_output_id = NULL`; the FK was intentionally added nullable for compatibility.
- Marker Logs have no direct Cut Output or Bundle FK.
- Lay Roll has Lay→Cut Output→Bundle lineage for new flow, but exact Roll allocation to an individual Cut Output line is not persisted when one Lay has multiple outputs.
- No production database population was inspected; counts and real overlap frequency are unknown.

### 2.2 Existing Business Rules and locked decisions

- **BR-013:** inventory movement is ITS-only and append-only.
- **BR-017:** historical correction requires approved adjustment; no direct edit.
- **D-01 / clarified BR-031:** Lay Roll is sole Actual Fabric Consumption authority for new execution; Marker remains planning/efficiency and legacy evidence; historical Marker/Lay data must not be rewritten.
- **D-03 / BR-065:** no universal whole-MO output authority; stage evidence keeps its own meaning.
- **D-04 / BR-066:** ACTUAL and BACKFLUSH are exclusive per MO/material for future authoritative execution; existing historical overlaps remain unchanged.

No locked rule currently determines how a historical mixed Marker+Lay MO should be classified, whether one source may be tagged as historical authority, or whether an append-only reconciliation record is required.

### 2.3 Technical implementation

#### Marker history

```text
marker_logs
→ cut_order_id
→ roll_id
→ marker_length / plies / qty_used / efficiency
```

There is no unique business key per Cut Order/Roll and no direct Marker→Cut Output/Bundle relation.

#### Lay history

```text
lay_rolls
→ lay_id
→ fabric_roll_id
→ uom_id / qty_used
→ Lay
→ Cut Output
→ Bundle (new path)
```

`lay_rolls` is unique by `(lay_id, fabric_roll_id)`.

#### Shared dispatch

```text
fabric_dispatch_balances
unique (production_order_id, roll_id)
qty_consumed + qty_returned <= qty_dispatched
```

The dispatch balance protects total quantity but does not preserve a source split between Marker and Lay Roll. When both historical sources exist, the final dispatch aggregate alone cannot prove whether quantities are duplicates, distinct physical uses, or partially overlapping representations.

#### Legacy Bundle lineage

```text
bundles.cut_output_id = nullable FK
```

Historical Bundles without Cut Output are intentionally valid compatibility rows. The schema does not authorize synthetic Cut Output creation.

### 2.4 Conflict/gap

1. **Marker-only history:** evidence can identify the legacy Marker path, but D-01 does not retroactively convert it to Lay Roll.
2. **Lay-only history:** evidence can identify the Lay Roll path, but runtime population/completeness is unknown.
3. **Mixed Marker+Lay on one MO:** both may have already mutated shared dispatch and physical Roll controls; the correct total cannot be inferred from structure alone.
4. **Legacy Bundle without Cut Output:** downstream Bundle history is readable, but reverse lineage stops at Cut Order Line rather than Lay/Cut Output.
5. **Missing Roll/UOM/output lineage:** a quantity may not be safely normalized or assigned to one source.
6. **Shared dispatch consumption:** the aggregate is bounded but source attribution is absent.
7. **MO allocation overwrite/increment:** historical order of Marker and Lay completion can affect stored aggregate semantics.
8. **No population evidence:** repository code cannot establish which historical class is common or safe to auto-classify by quantity.

## 3. Historical classes

| Historical class | Evidence available | Safe automatic interpretation | Mutation safety |
|---|---|---|---|
| Marker-only with Roll/Cut Order/UOM | Legacy measured Marker usage | `LEGACY_MARKER_RECORDED` | Read-only; no Lay synthesis |
| Lay-only with Roll/Lay/UOM | Lay Roll usage; possible Cut Output/Bundle chain | `LAY_ROLL_RECORDED` | Read-only; no Marker synthesis |
| Mixed Marker + Lay on same MO | Both source families exist | `HISTORICAL_CONFLICT` only | Recalculation/complete mutation blocked |
| Legacy Bundle without Cut Output | Bundle→Cut Order Line/MO | `LEGACY_LINEAGE_INCOMPLETE` | No synthetic Cut Output |
| Missing Roll/UOM/output lineage | Incomplete source dimensions | `INSUFFICIENT_EVIDENCE` | No inferred conversion/link |
| Shared dispatch consumed by both | Bounded aggregate, no source split | `RECONCILIATION_REQUIRED` | No automatic netting/deduplication |

## 4. Candidate policies

### Candidate A — FROZEN AS RECORDED + CONFLICT FLAGS

```text
Marker-only          → LEGACY_MARKER_RECORDED
Lay-only             → LAY_ROLL_RECORDED
Mixed same MO        → HISTORICAL_CONFLICT
Bundle no Cut Output → LEGACY_LINEAGE_INCOMPLETE
Missing dimensions   → INSUFFICIENT_EVIDENCE
Shared dispatch      → RECONCILIATION_REQUIRED
```

Policy:

- preserve source rows and their original meaning;
- do not choose a historical winner automatically;
- do not calculate one authoritative mixed total;
- keep mutation/recompletion blocked for mixed or insufficient-evidence records;
- allow only read-only classification and reporting;
- if a real correction is required later, use a separately approved, case-specific append-only adjustment/reconciliation process under BR-013/017 and D-11;
- new execution continues to use D-01 Lay Roll.

**Strength:** highest historical integrity; no invented quantity.  
**Trade-off:** historical mixed MOs remain unresolved for authoritative totals until case review.  
**Classification:** recommended.

### Candidate B — APPROVED HISTORICAL SOURCE TAG PER MO

Policy:

- preserve all source rows;
- an authorized reviewer selects `MARKER`, `LAY_ROLL`, or `UNRESOLVED` as historical interpretation metadata per MO;
- no quantity row is rewritten;
- selected source is used for read models only after approval and audit.

**Strength:** can produce a chosen historical view without deleting evidence.  
**Trade-off:** requires case-by-case owner/reviewer judgment and a new metadata authority; selection can be wrong without physical documents.  
**Classification:** possible, but not supported by current rule/schema.

### Candidate C — APPEND-ONLY RECONCILIATION RECORD

Policy:

- preserve Marker/Lay/dispatch rows;
- create a separate approved reconciliation document containing source evidence, accepted quantity, variance, reason, approver, and accounting/inventory consequences;
- read models use the reconciliation result without altering source rows.

**Strength:** strongest path when authoritative historical reporting must be produced.  
**Trade-off:** requires a new document lifecycle, approval, UOM and valuation rules, and D-11 reversal behavior.  
**Classification:** controlled future option; not implementation-ready.

### Candidate D — CONTROLLED BACKFILL / STANDARDIZATION

Policy:

- convert or synthesize missing Lay/Cut Output/Bundle/consumption relationships to one standard historical path.

**Strength:** superficially simplifies reporting.  
**Trade-off:** requires assumptions about physical events, UOM, duplicates, and output lineage; conflicts with current no-rewrite boundary and D-01 historical protection.  
**Classification:** not recommended; prohibited unless separately and explicitly authorized with evidence.

### Candidate E — KEEP UNDEFINED / READ-ONLY BLOCK

Policy:

- preserve all data and current guards;
- do not add a formal classification policy;
- keep all mixed/history-dependent authoritative functions blocked.

**Strength:** no invented rule.  
**Trade-off:** leaves D-02 open and downstream historical reporting ambiguous.  
**Classification:** safe deferral, not closure.

## 5. Comparison matrix

| Dimension | A — Frozen + flags | B — Source tag | C — Reconciliation record | D — Backfill | E — Undefined |
|---|---|---|---|---|---|
| Source rows preserved | Yes | Yes | Yes | Not necessarily | Yes |
| Automatic winner | No | No; approved selection | Reconciled result | Yes/implicit | No |
| Quantity inference | None | Source-dependent | Explicit approved result | High | None |
| Handles mixed MO | Flags only | Manual tag | Formal resolution | Rewrites/synthesizes | Blocks |
| Handles legacy Bundle | Reads incomplete | Metadata possible | Can document gap | Synthesizes | Blocks |
| Handles missing UOM | Insufficient evidence | Reviewer risk | Must resolve explicitly | Assumption risk | Blocks |
| Handles shared dispatch | Reconciliation required | Source tag may not dedupe | Explicit case record | Assumption risk | Blocks |
| BR-013/017 alignment | Highest | High if metadata-only | High if append-only/approved | Low | High |
| Historical integrity risk | Lowest | Medium | Low/medium | Highest | Lowest |
| New schema likely later | Optional | Yes | Yes | Yes | No |
| Immediate authoritative total | No | After approval | After reconciliation | Yes, risky | No |
| No rewrite/backfill | Yes | Yes | Yes | No | Yes |

## 6. Recommendation

```text
Recommendation:
A — FROZEN AS RECORDED + CONFLICT FLAGS

Rationale:
- matches the actual evidence available in repository structures;
- obeys D-01's no historical rewrite rule;
- preserves BR-013/017 append-only and approved-correction principles;
- does not fabricate source attribution from a shared dispatch aggregate;
- keeps legacy Bundles and incomplete lineage readable without synthetic records;
- leaves a controlled path for future case-specific approved reconciliation when evidence exists.

Main consequence:
Historical mixed MOs do not receive one automatic authoritative consumption total.
They remain visible as conflict/reconciliation-required evidence.

Implementation consequence:
A future implementation may add read-only classifications and guards. Any correction
or authoritative reconciled value requires a separate approved document/design; no
source-row mutation or backfill follows from this decision.
```

This recommendation is not the decision.

## 7. Decision options for Business Owner

```text
A — FROZEN AS RECORDED + CONFLICT FLAGS
B — APPROVED HISTORICAL SOURCE TAG PER MO
C — APPEND-ONLY RECONCILIATION RECORD
D — CONTROLLED BACKFILL / STANDARDIZATION
E — KEEP UNDEFINED / READ-ONLY BLOCK
```

## 8. Impact and dependencies

### Impacted modules

Cutting/Marker; Lay/Lay Roll; Fabric Roll; dispatch balance; MO material allocations; Cut Output; Bundle; Shop Floor lineage; Actual Cost history; inventory/audit/approval; reporting/governance.

### Implementation consequence

No implementation is authorized now. Depending on the selected policy, a later phase may add read-only classification, approved metadata, or a reconciliation document. Legacy endpoints and rows remain untouched.

### Historical-data consequence

This analysis performs no rewrite, backfill, deduplication, source selection, quantity netting, UOM inference, or synthetic lineage creation.

### Dependency state

```text
D-01 = LAY_ROLL — LOCKED
D-03 = SEPARATE_NAMED_MEASURES — LOCKED
D-04 = EXCLUSIVE_PER_MATERIAL_REQUIRED_NAMED_STAGE — LOCKED
D-02 = PENDING BUSINESS DECISION
        ↓
D-05 = NEXT only after D-02 is LOCKED
```

## 9. Decision record template

```text
D-02 — Historical Marker/Lay Mixed Path Policy

Status:
PENDING BUSINESS DECISION

Candidates:
A. FROZEN_AS_RECORDED_CONFLICT_FLAGS
B. APPROVED_HISTORICAL_SOURCE_TAG_PER_MO
C. APPEND_ONLY_RECONCILIATION_RECORD
D. CONTROLLED_BACKFILL_STANDARDIZATION
E. KEEP_UNDEFINED_READ_ONLY_BLOCK

Recommendation:
A — FROZEN AS RECORDED + CONFLICT FLAGS

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-02 remains **PENDING BUSINESS DECISION** and D-05 must not be closed.