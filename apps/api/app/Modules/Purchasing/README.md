# Modul Purchasing

PR → RFQ/quotation → PO → supplier invoice + 3-way match.

## Endpoint
| Method | Path | Permission | Rule |
|---|---|---|---|
| POST | `/api/purchasing/prs` | `purchasing.pr.create` | PR manual (MRP dari Phase 5) |
| POST | `/api/purchasing/prs/{id}/submit` | `purchasing.pr.submit` | approval berjenjang (BR-015) |
| GET/POST | `/api/purchasing/pos` | `purchasing.po.view/create` | total dihitung server-side |
| POST | `/api/purchasing/pos/{id}/submit` | `purchasing.po.submit` | |
| POST | `/api/purchasing/invoices` | `purchasing.invoice.create` | supplier invoice |
| POST | `/api/purchasing/invoices/{id}/match` | `purchasing.invoice.match` | **3-way match (BR-050)** |

## Aturan bisnis
- **BR-050**: match invoice vs PO (harga, toleransi %) vs GR (qty received) → MATCHED/MISMATCH.
- **BR-051**: partial receiving — `po_lines.received_qty` diagregasi dari GR; status PO PARTIAL_RECEIVED/RECEIVED otomatis.
- Approval PR/PO via approval matrix by nilai (BR-015) — event `DocumentApproved` doc_type `PR`/`PO`.
- Traceability: `po_lines.pr_line_id` → PR (BR-120).
