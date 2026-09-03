# BATCH 6 — D-11 ACCOUNTING CORRECTION

Status: LOCKED FOR IMPLEMENTATION  
Decision: `OPEN_PERIOD_REVERSAL_REPOST_CLOSED_PERIOD_PROSPECTIVE_ADJUSTMENT`

## Executable boundary

D-11 accepts only an existing authoritative POSTED journal with intact source lineage. The currently executable costing/shipment source is D-10 `SHIPMENT_COGS`. D-06, D-07, and D-09 do not currently produce accounting journals; attempts to correct them fail closed rather than inventing accounting output.

All nonzero corrections use the centralized `ApprovalEngine` with document type `ACCOUNTING_CORRECTION`. An active approval flow must be configured. Identical original and corrected amounts produce immutable `NO_CHANGE` without approval or journals.

## Open period

The original GL period must still be OPEN. In one transaction, existing `JournalService::post()` creates an exact append-only reversal and a corrected repost using the original company mapping. The new reversal references the original through `reverses_journal_id`; the correction document references original, reversal, and corrected repost. The original journal and its status are never updated. A corrected amount of zero is represented by the complete reversal with no invalid zero-value repost lines.

## Closed period

The original period remains CLOSED. After approval, the difference `corrected amount - original amount` is posted as a prospective adjustment in the current calendar period only when that period already exists and is OPEN. Negative differences reverse the mapped debit/credit direction. No period is created, reopened, or selected from an arbitrary future month.

## Identity and immutability

Identity is company × original journal × correction version. The source hash binds the original journal header/lines, authoritative D-10/D-08 lineage, original/corrected/difference amounts, currency, period state/mode, reason, source document, mapping, and approved target period. Same identity/source replays; changed payload conflicts. Only one active or posted correction can target an original journal.

No historical backfill, original mutation, second journal engine, materiality threshold, retained-earnings rule, FX conversion, or statutory-restatement rule is introduced.
