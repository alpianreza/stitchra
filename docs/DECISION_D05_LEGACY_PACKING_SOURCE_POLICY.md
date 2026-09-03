# STITCHRA — D-05 LEGACY PACKING SOURCE POLICY

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `c9a60e6d4d3f866ebc849433cdcec00d8c106ed9`  
> **Dependency:** D-02 is LOCKED; D-05 is the next decision in the approved sequence.  
> **Boundary:** No code, migration, backfill, source rewrite, production behavior change, or legacy endpoint removal is authorized.

## 1. Decision question

How may a legacy Packing List with missing `qc_inspection_id` be read, mutated, finalized, or linked to QC FINAL PASS without fabricating historical provenance?

## 2. Evidence separation

### 2.1 Existing behavior

- The base `packing_lists` table permits nullable `production_order_id`.
- Migration `2026_09_02_000026_add_packing_input_source.php` added nullable `qc_inspection_id` with a restrict-on-delete FK and company/source index. Nullable was intentionally retained for historical compatibility.
- Current API creation requires a same-company `production_order_id`.
- `PackingService::create()` requires an MO, finds the latest FINAL PASS, but persists `qc_inspection_id` only when the MO is already in status `QC`. A DRAFT Packing List can therefore be created with a null source when the MO is not yet QC.
- Adding a Carton and finalizing a Packing List both call `assertPackingInput()`.
- `assertPackingInput()` requires MO status `QC` and an exact same-company/same-MO FINAL PASS.
- If `qc_inspection_id` is null, the service selects the latest FINAL PASS and writes it to the Packing List with an audit record.
- Cumulative non-cancelled Carton quantity is limited to the selected FINAL PASS `lot_qty`.
- Finalization revalidates the source, posts ITS `PRODUCTION_RECEIPT`, and changes the Packing List to APPROVED.
- Lineage output explicitly reports `MISSING_LEGACY_SOURCE` when the relationship is absent.
- The public routes expose create, add Carton, finalize, read, and lineage. There is no explicit controlled legacy-source attachment endpoint or approval flow.

### 2.2 Existing Business Rules

- **BR-013:** inventory changes use ITS and are append-only.
- **BR-016:** important transactions require audit trail.
- **BR-017:** historical correction requires approved adjustment, not direct editing.
- **BR-080:** only QC PASS pieces may enter Carton.
- **PF-07:** FINAL PASS is the quality eligibility boundary.
- **PF-09:** Packing/Carton produces FG through `PRODUCTION_RECEIPT`.
- **D-03 / BR-065:** QC FINAL PASS, Packing quantity, and FG receipt are separate named measures.

No locked rule defines whether a legacy source-less Packing List may be permanently grandfathered, automatically linked to a later/latest PASS, linked by controlled approval, or prohibited from all mutation.

### 2.3 Technical implementation

```text
packing_lists
- company_id
- sales_order_id
- production_order_id nullable
- qc_inspection_id nullable FK
- status DRAFT/SUBMITTED/APPROVED/SHIPPED/CANCELLED
```

Current mutation path:

```text
Packing List with qc_inspection_id NULL
→ add Carton/finalize
→ require MO status QC
→ find latest FINAL PASS for same company + MO
→ write qc_inspection_id
→ audit update
→ enforce cumulative Carton qty <= PASS lot_qty
```

Current read path:

```text
missing source
→ remains readable
→ lineage = MISSING_LEGACY_SOURCE
```

The auto-attachment is auditable but has no explicit reason, approval, evidence document, or rule proving that the latest PASS is the actual historical source.

### 2.4 Conflict/gap

1. Nullable source is required for historical readability but weakens provenance.
2. Auto-attaching the latest PASS can reinterpret history when multiple QC cycles exist.
3. An existing Carton may predate the selected PASS, so chronology may conflict.
4. `lot_qty` constrains total quantity but does not prove that specific Cartons came from that inspection.
5. Direct Bundle/Finishing Output→Carton allocation is not defined.
6. A source-less DRAFT can currently be created before the MO reaches QC.
7. There is no explicit approval/reason workflow for historical attachment.
8. Approved/Shipped rows may already have downstream ITS/Shipment facts; retroactive source changes could broaden the effect of a historical assertion.
9. Production data population was not inspected; the frequency and age of missing-source rows are unknown.

## 3. Candidate policies

### Candidate A — READ-ONLY UNTIL APPROVED SOURCE ATTACHMENT

Policy:

- Source-less legacy Packing Lists remain readable and retain `MISSING_LEGACY_SOURCE`.
- No Carton mutation, finalize, FG receipt, Shipment creation, or status progression may occur while source is missing.
- A source may be attached only through an explicit controlled action with reason, identified user, audit, and approval.
- Candidate source must be exact same company, SO/MO, FINAL stage, PASS verdict, chronologically plausible, and quantity-compatible.
- Attachment does not rewrite Carton, QC, ITS, or Shipment rows.
- If evidence is insufficient or downstream facts conflict, attachment is rejected and the row remains read-only.

**Strength:** preserves compatibility while allowing evidence-backed recovery.  
**Risk:** requires approval workflow and case review.  
**Classification:** recommended.

### Candidate B — PERMANENT READ-ONLY GRANDFATHERING

Policy:

- Every source-less legacy Packing List remains readable forever.
- No source may be attached and no mutation/finalization/shipment is permitted.

**Strength:** strongest protection against historical reinterpretation.  
**Risk:** valid legacy work cannot be completed even when exact QC evidence exists.  
**Classification:** safe but operationally restrictive.

### Candidate C — AUTO-ATTACH LATEST ELIGIBLE FINAL PASS

Policy:

- Preserve the existing service behavior: on mutation, attach the latest same-MO FINAL PASS, audit the update, then proceed if quantity fits.

**Strength:** minimal friction and closest to current technical behavior.  
**Risk:** latest PASS is not necessarily the actual historical source; cycles and chronology can create false lineage.  
**Classification:** not recommended without explicit owner acceptance of inference risk.

### Candidate D — ELIGIBLE MUTATION WITHOUT PERSISTED SOURCE

Policy:

- A source-less Packing List may mutate/finalize when any same-MO FINAL PASS exists, without writing `qc_inspection_id`.

**Strength:** avoids retroactive linkage.  
**Risk:** violates traceability intent, leaves FG receipt without a persisted QC source, and weakens BR-080 evidence.  
**Classification:** not recommended.

### Candidate E — KEEP UNDEFINED / BLOCK ALL SOURCE-LESS MUTATION

Policy:

- Preserve read access and current missing-source classification.
- Block authoritative mutation while D-05 remains open; define no attachment policy.

**Strength:** no invented rule.  
**Risk:** does not close D-05 and blocks D-06 progression.  
**Classification:** safe deferral.

## 4. Comparison matrix

| Dimension | A — Approved attachment | B — Permanent read-only | C — Auto latest PASS | D — No persisted source | E — Undefined |
|---|---|---|---|---|---|
| Historical rows readable | Yes | Yes | Yes | Yes | Yes |
| Mutation with missing source | Blocked | Blocked | Allowed after auto-link | Allowed | Blocked |
| Source inference | Controlled evidence | None | Latest-PASS inference | None/persistently missing | None |
| Reason/user/audit | Required | N/A | Audit only | Mutation audit only | N/A |
| Approval | Required | N/A | No | No | N/A |
| BR-080 traceability | Strong after approval | No progression | Medium | Weak | Blocked |
| Multiple QC cycles | Explicitly reviewed | No issue | High ambiguity | Ambiguous | Unresolved |
| Downstream conflict check | Required | N/A | Partial | Partial | N/A |
| Historical rewrite | No | No | Relationship mutation | No relationship | No |
| Operational recovery | Controlled | None | Easy | Easy/weak | None |
| Governance risk | Lowest balanced | Low | Medium/high | High | Deferred |

## 5. Recommendation

```text
Recommendation:
A — READ-ONLY UNTIL APPROVED SOURCE ATTACHMENT

Rationale:
- preserves nullable legacy rows and explicit MISSING_LEGACY_SOURCE evidence;
- enforces BR-080 before any new Carton/FG/Shipment mutation;
- prevents silent latest-cycle inference;
- allows recovery only when exact same-company/MO/QC evidence is available;
- keeps source rows and downstream transactions unchanged;
- aligns with BR-016/017 approval and audit principles.

Implementation consequence:
A later implementation phase would add a controlled attachment action and guards.
It must not backfill automatically, and it must fail closed on chronology, quantity,
tenant, or downstream conflicts.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — READ-ONLY UNTIL APPROVED SOURCE ATTACHMENT
B — PERMANENT READ-ONLY GRANDFATHERING
C — AUTO-ATTACH LATEST ELIGIBLE FINAL PASS
D — ELIGIBLE MUTATION WITHOUT PERSISTED SOURCE
E — KEEP UNDEFINED / BLOCK SOURCE-LESS MUTATION
```

## 7. Impact and dependencies

### Impacted modules

Packing List; Carton; QC Inspection/NCR cycles; MO/SO; ITS Production Receipt; Shipment; audit/approval; lineage/reporting; UI/API.

### Implementation consequence

No implementation is authorized now. The selected policy may later require mutation guards, explicit attachment action, approval, reason/audit fields, chronology and quantity checks, downstream conflict detection, and tests.

### Historical-data consequence

No legacy Packing List, QC Inspection, Carton, ITS ledger, or Shipment row is rewritten or backfilled by this analysis.

### Dependency state

```text
D-02 = LOCKED
D-05 = PENDING BUSINESS DECISION
        ↓
D-06 WIP Valuation = NEXT only after D-05 is LOCKED
```

## 8. Decision record template

```text
D-05 — Legacy Packing Source Policy

Status:
PENDING BUSINESS DECISION

Candidates:
A. READ_ONLY_UNTIL_APPROVED_SOURCE_ATTACHMENT
B. PERMANENT_READ_ONLY_GRANDFATHERING
C. AUTO_ATTACH_LATEST_ELIGIBLE_FINAL_PASS
D. ELIGIBLE_MUTATION_WITHOUT_PERSISTED_SOURCE
E. KEEP_UNDEFINED_BLOCK_SOURCELESS_MUTATION

Recommendation:
A — READ-ONLY UNTIL APPROVED SOURCE ATTACHMENT

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-05 remains **PENDING BUSINESS DECISION** and D-06 must not be closed.