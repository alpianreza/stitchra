# Finance

## WIP / FG valuation and COGS boundary

- Iteration 12 is a read-only authority boundary. ITS remains quantity authority; no parallel inventory, valuation, costing, or journal ledger is introduced.
- `GET finance/gl/valuation-authority` exposes Material Issue→WIP, Material Return, Production Output→FG, FG→Shipment, and Shipment→COGS authority states.
- `GET finance/gl/valuation-boundaries/production-orders/{productionOrder}` traces material issue/return, production scans, WIP transfers, Packing Lists, and ITS `PRODUCTION_RECEIPT` without promoting source cost evidence into WIP or FG valuation.
- `GET finance/gl/valuation-boundaries/shipments/{shipment}` traces Packing List receipt, FG quantity, and ITS `SHIPMENT`, then stops before valuation/COGS when no authoritative amount exists.
- Material Issue rows may carry BR-005 moving-average cost for Iteration 10 Actual Cost evidence. This does not define a WIP debit/valuation layer; `MATERIAL_ISSUE` posting remains blocked.
- Material Return can carry an unambiguous source issue cost, but mixed-cost allocation, WIP reversal, accounting date, and accounting event remain `NOT DEFINED`.
- `PRODUCTION_RECEIPT` and `SHIPMENT` remain valid operational quantity movements. Current calls do not pass an authoritative FG unit cost; FG moving average, shipment valuation, and COGS amount therefore remain `NOT DEFINED`.
- BR-083 identifies Shipment as a COGS boundary and `SHIPMENT_COGS` is a registered mapping event, but neither defines the amount. No journal is created from mapping presence alone.
- Iteration 10 Actual Cost remains computed read-only. No persistent Actual Cost document, cost-per-unit denominator, WIP/FG bridge, standard-cost substitution, backfill, or FX inventory rule is invented.
- Operational cancellation/reversal accounting and late-transaction treatment remain `NOT DEFINED`. Existing GL period and journal reversal controls are unchanged.

## Operational posting / GL

- BR-101 reuses the existing `journals`, `journal_lines`, `gl_periods`, `account_mappings`, numbering, audit, posting-key idempotency, and reversal architecture. No parallel GL or accounting ledger exists.
- The operational authority matrix is exposed at `GET finance/gl/operational-authority`.
- Safe Iteration 11 write scope is intentionally narrow: a POSTED Goods Receipt can be posted explicitly through `POST finance/gl/operational-postings/goods-receipts/{goodsReceipt}` only when it has exactly one traceable ITS `PURCHASE_RECEIPT`, all COMPANY ledger rows are fully valued, source currency equals company base currency, the `GR_RECEIPT` account mapping is configured, and the source-date GL period is OPEN.
- GR amount is the sum of the stored ITS transaction costs. Posting date is the persisted `goods_receipts.received_date`; the transaction is never silently moved to another period.
- The deterministic posting key `(company,event,source type,source id)` prevents duplicate journals; conflicting reprocessing is rejected.
- Journal lineage is exposed at `GET finance/journals/{journal}/lineage` and includes account lines, GL period, operational source, ITS movement when supported, and reversal/original references.
- Existing JournalService reversal remains append-only for lines: it creates a reversing journal, links it uniquely to the original, marks the original VOID, and records audit evidence. Closed-period behavior continues to fail through the existing OPEN-period gate.
- Existing AR/AP/tax/payment/FX automatic posting remains unchanged.

## Actual Costing / Production Cost

- BR-009 authority is an **actual cost computed per Production Order/MO**.
- The service is a read-only cost view. It does not create an inventory movement, journal, or parallel costing ledger.
- Material trace is read from valued `MATERIAL_ISSUE` less valued `PRODUCTION_RETURN` rows in the append-only ITS ledger. Buyer-owned material is excluded from valuation under BR-001.
- New ACTUAL/BACKFLUSH issue rows receive the exact current COMPANY moving-average cost from their locked stock balance. Historical rows are not backfilled; missing values remain explicit `PARTIAL` evidence.
- Labor and overhead reuse BR-009 only: output × routing total SAM × configured line/OH rate for the selected period.
- Production output prefers final-routing-operation OUT scans. Legacy `production_orders.qty_produced` remains a compatibility fallback but is explicitly non-authoritative because its writer is not defined.
- Subcontract cost is BR-091: `subcon_fees → Job Work Order → vendor → MO`.
- The approved standard-cost snapshot remains immutable and is used for component variance. Variance is marked PARTIAL whenever the output or any actual source is incomplete.
- Cost per unit is not published because the authoritative denominator is not defined.
- Machine cost, separate wastage valuation/allocation, other actual cost, WIP valuation, FG valuation, actual-cost persistence/lifecycle, recalculation, and close/reopen behavior remain `NOT_DEFINED` or not implemented.

## Formal period closing

- Closing uses `prepare → approve → close`; the former direct close API route is removed.
- Deterministic checklist snapshots cover journal balance, unresolved AP, bank reconciliation, current FX revaluation, prior FX reversal, backup verification, and tax review.
- Snapshot hash is recomputed before approval and close. Changed accounting data makes the checklist stale and requires preparation again.
- Maker and approver must be different users.
- Approved checklist inputs are immutable and every lifecycle action is audited.

## Bank, FX, and tax

- Bank statements support deduplicated import, partial matching, fees, ignored reasons, and approval locking.
- Foreign invoices support realized settlement FX and period-end revaluation/reversal.
- Tax and withholding lines are immutable snapshots.

## Still pending

Defined WIP/FG valuation, authoritative cost-per-unit denominator, COGS amount authority, operational cancellation/reversal accounting, cross-currency inventory treatment, actual-cost persistence/lifecycle, country-specific tax filing/e-invoicing, and runtime, accounting, security, concurrency, and UAT verification.
