# STITCHRA — D-11 REVERSAL AND TIMING POLICY

> **Type:** Business Rule decision analysis only  
> **Status:** PENDING BUSINESS DECISION  
> **Analysis date:** 3 September 2026  
> **Repository baseline:** `alpianreza/stitchra` / `main` / `391149d4a76288feac3a690fb059976940122816`  
> **Dependency:** D-10 is LOCKED to Shipment-date base COGS plus later actual variance.  
> **Boundary:** No code, migration, journal/reversal posting, period reopening, backfill, historical rewrite, or production behavior change is authorized.

## 1. Decision question

How must Stitchra account for corrections, reversals, cancellations, returns, and late actual-cost variance when the original operational/GL period is OPEN versus CLOSED?

D-11 must preserve both operational evidence and closed-period integrity. It must not silently move dates, rewrite source transactions, or reopen periods without explicit authority.

## 2. Evidence separation

### 2.1 Existing behavior

- `JournalService::post()` requires journal date to fall inside the supplied period and blocks a CLOSED period.
- Posting creates balanced, POSTED journals with audit trail and optional source/event/posting key.
- `JournalService::reverse()` attempts reversal in the original period at period month-end.
- `JournalService::reverseIntoPeriod()` can post a reversal in another OPEN period, links `reverses_journal_id`, marks the original journal `VOID`, and prevents a second reversal.
- `GlPostingService` uses a deterministic key per company/event/source type/source ID and fails on amount/date/period conflicts.
- `PeriodCloseService` has prepare/approve/close, maker-checker separation, checklist hash, and immutable CLOSED status in normal posting.
- Operational source services do not consistently create reversal documents for Material Issue, FG Receipt, Shipment, or COGS.
- `ValuationBoundaryService` and `OperationalPostingService` explicitly state late-transaction and operational-reversal accounting are `NOT DEFINED`.
- D-07 through D-10 require append-only actual variance after provisional WIP/FG and Shipment-date COGS.

### 2.2 Existing Business Rules and locked decisions

- **BR-013:** ITS/stock ledger is append-only.
- **BR-016:** transactions require audit trail.
- **BR-017:** historical corrections require approved adjustment.
- **BR-101:** journals require configured account mapping and must balance.
- **BR-103:** CLOSED period blocks posting.
- **BR-105/106:** FG uses provisional standard, later actual variance, and FG received denominator.
- **BR-107:** Shipment consumes prevailing FG Moving Average.
- **BR-108:** base COGS is recognized at Shipment; later actual variance is separate and append-only.

No locked rule currently states whether to reopen a CLOSED period, post a prospective prior-period adjustment, always use the current period, block corrections, or how operational reversals must precede accounting reversals.

### 2.3 Technical implementation

Current journal mechanics:

```text
Post:
source event + amount + journal date + period + mapping
→ balanced POSTED journal
→ deterministic key for supported AUTO events

Reverse:
original journal
→ reversed debit/credit lines
→ linked reversal journal
→ original status VOID
```

Current constraints:

```text
journal_date month = journal period
CLOSED period = posting blocked
one reversal per original journal
one AUTO base posting per company/event/source
```

Important technical conflict:

`reverseIntoPeriod()` can place reversal lines in a later OPEN period while changing the original journal status to `VOID`. That implementation exists, but it is not an approved accounting rule for closed-period correction and must not be treated as authority.

### 2.4 Conflict/gap

1. Base event date and correction-discovery/approval date may be in different periods.
2. Original period may be CLOSED and must not be silently reopened.
3. Marking an original closed-period journal VOID while reversal lines sit in a later period can confuse historical reporting.
4. Operational quantity reversal may be absent even if a financial reversal is requested.
5. Late actual cost requires a separate variance event and allocation between WIP, FG on hand, and COGS.
6. Shipment cancellation/customer return has no complete operational reversal flow.
7. No rule defines prior-period-adjustment account mapping, approval, or materiality treatment.
8. No rule defines correction version/idempotency beyond one base posting and one journal reversal.
9. Current period creation can occur automatically, but financial posting still requires deliberate event authority.
10. Historical rows must not be auto-voided, reposted, backfilled, or reclassified.

## 3. Candidate policies

### Candidate A — OPEN-PERIOD REVERSAL/REPOST; CLOSED-PERIOD PROSPECTIVE ADJUSTMENT

Policy:

- If the original economic/source period is OPEN, post an append-only reversal and corrected repost in that same period, preserving source linkage and reason.
- If the original period is CLOSED, do not reopen, void, or mutate the original closed-period journal. Post a separate approved prior-period adjustment/late-variance entry in the current OPEN period.
- Preserve original source date, original period, discovery/finalization date, posting date, reason, user, approval, and audit references.
- Operational reversal/return/cancellation evidence must exist before its accounting consequence is posted.
- Missing mapping, approval, lineage, allocation, or open target period fails closed.

**Strength:** preserves closed-period integrity, append-only auditability, and normal correction capability.  
**Gap:** requires a separate adjustment event/document and configured accounting mapping; materiality/statutory restatement remains a controlled accounting exception.  
**Classification:** recommended.

### Candidate B — REOPEN ORIGINAL CLOSED PERIOD WITH APPROVAL

Policy:

- Approved corrections reopen the original period, reverse/repost there, and close the period again.

**Strength:** restates the original period directly.  
**Gap:** undermines BR-103 close integrity, changes previously closed reporting, and requires external financial-control authority not present in Stitchra.  
**Classification:** high risk; not recommended as the default.

### Candidate C — ALWAYS POST CORRECTION IN CURRENT OPEN PERIOD

Policy:

- All reversals, corrections, and variances post in the current OPEN period even when the original period is still OPEN.

**Strength:** simple and never reopens history.  
**Gap:** unnecessarily distorts the current period and loses same-period correction where available.  
**Classification:** safe but less accurate than Candidate A.

### Candidate D — BLOCK ALL CORRECTIONS AFTER PERIOD CLOSE

Policy:

- Same-period corrections are allowed while OPEN; once CLOSED, no correction/variance may be posted.

**Strength:** strongest period immutability.  
**Gap:** late supplier/subcon cost, actual variance, returns, and discovered errors cannot be represented; conflicts with D-07/D-10 convergence.  
**Classification:** operationally incomplete.

### Candidate E — KEEP REVERSAL/TIMING UNDEFINED / BLOCK NEW VALUATION POSTING

Policy:

- Keep WIP/FG/Shipment/COGS valuation and variance posting blocked.

**Strength:** matches the current safe boundary and invents nothing.  
**Gap:** prevents implementation of the already locked valuation chain.  
**Classification:** safe deferral, not closure.

## 4. Comparison matrix

| Dimension | A — Open correction / closed adjustment | B — Reopen | C — Always current | D — Block after close | E — Undefined |
|---|---|---|---|---|---|
| Preserves CLOSED-period integrity | Highest | Low | High | Highest | Highest |
| Same-period accuracy while OPEN | Yes | Yes | No | Yes | No posting |
| Supports late actual variance | Yes | Yes | Yes | No | No |
| Requires restatement of closed reports | No by default | Yes | No | No | No |
| Supports operational returns/corrections | Yes with source evidence | Yes | Yes | Only before close | No |
| Aligns append-only controls | Highest | Medium | High | High | Neutral |
| Requires new adjustment event | Yes | Reopen workflow | Yes | No | No |
| Historical auto-rewrite | No | Potentially | No | No | No |
| Control complexity | High but explicit | Highest/risky | Medium | Low | Lowest |

## 5. Recommendation

```text
Recommendation:
A — OPEN-PERIOD REVERSAL/REPOST; CLOSED-PERIOD PROSPECTIVE ADJUSTMENT

When original period is OPEN:
- use an explicit reversal and corrected repost in the original period;
- retain source date, reason, identified user, approval, audit, and idempotency.

When original period is CLOSED:
- never reopen or silently change the original journal;
- never mark the original closed-period journal VOID merely because a later-period
  adjustment is posted;
- post a separate approved prior-period adjustment/late-variance entry in the
  current OPEN period;
- retain original source date/period and adjustment discovery/finalization/posting
  dates as separate evidence.

Operational precedence:
An operational return/cancellation/adjustment document and ITS movement must exist
before the accounting correction when quantity/stock is affected.

Fail closed:
Missing source lineage, reason, identified user, approval, mapping, allocation,
open target period, or deterministic correction key.

No automatic materiality or retained-earnings rule:
The accounting mapping must be explicitly configured. Statutory restatement or
materiality treatment requires separate Finance authority; it is not inferred.

Historical consequence:
No existing operational transaction or journal is automatically voided, reposted,
backfilled, or reclassified.
```

This recommendation is not the decision.

## 6. Decision options for Business Owner

```text
A — OPEN-PERIOD REVERSAL/REPOST; CLOSED-PERIOD PROSPECTIVE ADJUSTMENT
B — REOPEN ORIGINAL CLOSED PERIOD WITH APPROVAL
C — ALWAYS POST IN CURRENT OPEN PERIOD
D — BLOCK ALL CORRECTIONS AFTER CLOSE
E — KEEP UNDEFINED / BLOCK NEW VALUATION POSTING
```

## 7. Impact and dependencies

### Impacted modules

Journal/GL; Period Close; ITS adjustments/returns; Material Issue/Return; WIP/FG valuation; Shipment/return/cancellation; Actual Cost variance; COGS; account mapping; approvals; audit; reporting.

### Implementation consequence

No implementation is authorized. A later phase may require operational reversal documents, separate prior-period/variance events, immutable closed-journal handling, correction version keys, account mappings, approval/audit controls, allocation logic, period validation, and tests.

### Historical-data consequence

No historical operational row, ITS movement, valuation, journal, status, or period is changed or backfilled by this analysis.

### Dependency state

```text
D-10 = LOCKED
D-11 = PENDING BUSINESS DECISION
        ↓
Decision Closure Round 1 = COMPLETE only after D-11 is selected and LOCKED
```

## 8. Decision record template

```text
D-11 — Reversal and Timing Policy

Status:
PENDING BUSINESS DECISION

Candidates:
A. OPEN_PERIOD_REVERSAL_REPOST_CLOSED_PERIOD_PROSPECTIVE_ADJUSTMENT
B. REOPEN_ORIGINAL_CLOSED_PERIOD_WITH_APPROVAL
C. ALWAYS_POST_CURRENT_OPEN_PERIOD
D. BLOCK_ALL_CORRECTIONS_AFTER_CLOSE
E. KEEP_UNDEFINED_BLOCK_NEW_VALUATION_POSTING

Recommendation:
A — OPEN-PERIOD REVERSAL/REPOST; CLOSED-PERIOD PROSPECTIVE ADJUSTMENT

Decision:
PENDING

Decision Owner:
PENDING

Decision Date:
PENDING
```

Until the Business Owner selects an option, D-11 remains **PENDING BUSINESS DECISION** and Decision Closure Round 1 must not be declared complete.