# Iteration 26 — Commercial Invoice, Export Documents & Container

Status: **IMPLEMENTED / STATIC READBACK PENDING / RUNTIME NOT RUN**

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
Mandatory document combinations per country, destination, incoterm, buyer, or LC are not defined. No invented completeness blocker, customs workflow, carrier integration, or file binary storage was added. Shipment stock-out, valuation, COGS, tax, and GL authority remain unchanged.

## Verification
Static readback pending. Migration, Pest, TypeScript, Next build, API/E2E, approval-flow, and concurrency verification are NOT RUN.
