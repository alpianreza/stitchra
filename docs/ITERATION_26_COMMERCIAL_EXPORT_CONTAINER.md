# Iteration 26 — Commercial Invoice, Export Documents & Container

Status: **IMPLEMENTED / STATIC READBACK PASS / RUNTIME NOT RUN**

## Scope
- Add Shipment-scoped Container, Commercial Invoice, normalized CI lines, and Export Document evidence.
- Derive Commercial Invoice lines from immutable Shipment matrix quantity and Sales Order matrix prices.
- Snapshot Sales Order currency and exchange rate into Commercial Invoice.
- Require an ISSUED Commercial Invoice before creating a new AR Invoice.
- Preserve historical AR rows through nullable `commercial_invoice_id` and no backfill.

## Controls
- One Commercial Invoice per Shipment; request cannot override quantity, price, currency, rate, or total.
- Container number is unique per company and belongs to one Shipment.
- Export document types are controlled: COO, Bill of Lading, LC Document, Customs, and Other.
- Draft export evidence requires a reference number or file reference before issue.
- Cancelled Shipments reject new commercial/export records.
- All mutations use tenant checks, transactions, row locks, audit records, and existing permissions.

## Boundary
Mandatory document combinations per country, destination, incoterm, buyer, or LC are not defined. No invented completeness blocker, customs workflow, carrier integration, or file binary storage was added. Shipment stock-out, valuation, COGS, tax, and GL authority remain unchanged. Commercial Invoice issue uses the existing controlled submit permission; the two-level Shipping Manager → Finance approval matrix remains configuration/runtime evidence, not claimed as executed.

## Verification
Static readback passed for migration, numbering, models, relations, service, controller, routes, Commercial Invoice → AR gate, authority read model, workbench, and navigation. Migration, Pest, TypeScript, Next build, API/E2E, approval-flow, and concurrency verification are NOT RUN.
