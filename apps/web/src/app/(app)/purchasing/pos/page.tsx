"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, DataTable, FilterBar, FilterSelect, Input, Modal, PageHeader, Select, StatusBadge, type DataTableColumn } from "@/components/ui";

interface Po {
  id: number;
  doc_no: string;
  status: string;
  order_date: string;
  expected_date: string | null;
  total_amount: string;
  supplier?: { name: string };
}
interface Page { data: Po[]; total: number }
interface PoLine { id: number; material_id: number; qty: string; received_qty: string; unit_price: string; uom_id: number; material?: { code: string; name: string } }
interface PoDetail { id: number; doc_no: string; supplier_id?: number; supplier?: { name: string }; lines: PoLine[] }
interface InvLine { po_line_id: string; qty: string; unit_price: string }
interface Invoice { id: number; doc_no?: string; match_status?: string; status?: string }

export default function PurchaseOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");
  const [acting, setActing] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [invOpen, setInvOpen] = useState(false);
  const [invPos, setInvPos] = useState<Po[]>([]);
  const [invPoId, setInvPoId] = useState("");
  const [invDetail, setInvDetail] = useState<PoDetail | null>(null);
  const [invLines, setInvLines] = useState<InvLine[]>([]);
  const [invNo, setInvNo] = useState("");
  const [invDate, setInvDate] = useState(new Date().toISOString().slice(0, 10));
  const [invDue, setInvDue] = useState("");
  const [invBusy, setInvBusy] = useState(false);
  const [invError, setInvError] = useState<string | null>(null);
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [matchStatus, setMatchStatus] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true); setError(null);
    api.get<Page>(`/purchasing/pos${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((requestError) => setError(requestError.message))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(load, [load]);

  async function submit(id: number) {
    setActing(id); setError(null);
    try { await api.post(`/purchasing/pos/${id}/submit`, {}); load(); }
    catch (requestError: any) { setError(requestError.message); }
    finally { setActing(null); }
  }

  function openInvoice() {
    setInvOpen(true); setInvError(null); setInvoice(null); setMatchStatus(null);
    setInvDetail(null); setInvLines([]); setInvPoId("");
    Promise.all([
      api.get<Page>("/purchasing/pos?status=APPROVED&per_page=100"),
      api.get<Page>("/purchasing/pos?status=PARTIAL_RECEIVED&per_page=100"),
    ]).then(([a, b]) => setInvPos([...a.data, ...b.data])).catch(() => {});
  }

  function selectInvPo(id: string) {
    setInvPoId(id); setInvDetail(null); setInvLines([]); setInvoice(null); setMatchStatus(null);
    if (!id) return;
    setInvBusy(true); setInvError(null);
    api.get<PoDetail>(`/purchasing/pos/${id}`)
      .then((detail) => {
        setInvDetail(detail);
        setInvLines(detail.lines.map((l) => ({
          po_line_id: String(l.id),
          qty: String(Math.max(0, Number(l.qty) - Number(l.received_qty)) || Number(l.qty)),
          unit_price: l.unit_price,
        })));
      })
      .catch((e) => setInvError(e.message))
      .finally(() => setInvBusy(false));
  }

  async function createInvoice() {
    if (!invDetail || !invDetail.supplier_id) { setInvError("PO tidak memiliki supplier."); return; }
    const lines = invLines.filter((l) => Number(l.qty) > 0);
    if (lines.length === 0) { setInvError("Isi qty untuk minimal 1 line."); return; }
    setInvBusy(true); setInvError(null);
    try {
      const r = await api.post<Invoice>("/purchasing/invoices", {
        supplier_id: invDetail.supplier_id,
        purchase_order_id: invDetail.id,
        supplier_invoice_no: invNo || undefined,
        invoice_date: invDate,
        due_date: invDue || undefined,
        lines: lines.map((l) => ({ po_line_id: Number(l.po_line_id), qty: Number(l.qty), unit_price: Number(l.unit_price) })),
      });
      setInvoice(r);
    } catch (e) {
      setInvError(e instanceof Error ? e.message : "Gagal membuat invoice");
    } finally { setInvBusy(false); }
  }

  async function matchInvoice() {
    if (!invoice) return;
    setInvBusy(true); setInvError(null);
    try {
      const r = await api.post<Invoice>(`/purchasing/invoices/${invoice.id}/match`, {});
      setInvoice(r); setMatchStatus(r.match_status ?? "MATCHED");
    } catch (e) {
      setInvError(e instanceof Error ? e.message : "Gagal match invoice");
    } finally { setInvBusy(false); }
  }
  const fmt = (value: string) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(Number(value));
  const columns: DataTableColumn<Po>[] = [
    { key: "document", header: "No. PO", cell: (po) => <span className="font-mono font-semibold">{po.doc_no}</span> },
    { key: "supplier", header: "Supplier", cell: (po) => po.supplier?.name ?? "—" },
    { key: "order", header: "Tgl Order", cell: (po) => po.order_date },
    { key: "expected", header: "Ekspektasi", cell: (po) => po.expected_date ?? "—" },
    { key: "total", header: "Total", align: "right", cell: (po) => <span className="font-medium tabular-nums">{fmt(po.total_amount)}</span> },
    { key: "status", header: "Status", cell: (po) => <StatusBadge status={po.status} /> },
    { key: "action", header: "Aksi", align: "right", cell: (po) => po.status === "DRAFT" ? <Button size="sm" variant="primary" loading={acting === po.id} onClick={() => submit(po.id)}>Submit</Button> : "—" },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Purchasing"
        title="Purchase Order"
        description="Kelola komitmen pembelian dan status penerimaan supplier."
        actions={
          <>
            <Link href="/purchasing/pos/new" className="inline-flex min-h-9 items-center rounded-[var(--radius-control)] bg-[var(--color-primary)] px-3 text-sm font-medium text-white hover:bg-[var(--color-primary-hover)]">Buat PO</Link>
            <Button size="sm" variant="secondary" onClick={openInvoice}>Supplier Invoice</Button>
          </>
        }
      />
      <FilterBar summary={page ? `${page.total} purchase order` : undefined}>
        <FilterSelect label="Status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">Semua status</option>
          {["DRAFT", "SUBMITTED", "APPROVED", "PARTIAL_RECEIVED", "RECEIVED", "CLOSED"].map((item) => <option key={item}>{item}</option>)}
        </FilterSelect>
      </FilterBar>
      <DataTable caption="Daftar purchase order" columns={columns} rows={page?.data ?? []} getRowKey={(po) => po.id} loading={loading} error={error} onRetry={load} emptyTitle="Belum ada purchase order" emptyDescription="Buat purchase order pertama untuk memulai proses pembelian." minWidth="900px" mobileCard={(po) => (
        <article className="space-y-3 p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-mono font-semibold">{po.doc_no}</p><p className="text-sm">{po.supplier?.name ?? "—"}</p></div><StatusBadge status={po.status} /></div><dl className="grid grid-cols-2 gap-2 text-xs"><div><dt className="text-[var(--color-text-muted)]">Ekspektasi</dt><dd className="font-medium">{po.expected_date ?? "—"}</dd></div><div><dt className="text-[var(--color-text-muted)]">Total</dt><dd className="font-semibold tabular-nums">{fmt(po.total_amount)}</dd></div></dl>{po.status === "DRAFT" && <Button className="w-full" size="sm" variant="primary" loading={acting === po.id} onClick={() => submit(po.id)}>Submit untuk Approval</Button>}</article>
      )} />
      <Modal
        open={invOpen}
        onClose={() => { if (!invBusy) setInvOpen(false); }}
        closeDisabled={invBusy}
        title="Supplier Invoice (3-way match)"
        description="Pilih PO APPROVED/PARTIAL_RECEIVED, isi qty & harga faktur, lalu jalankan match."
        size="lg"
        footer={
          <>
            <Button onClick={() => setInvOpen(false)} disabled={invBusy}>Tutup</Button>
            {!invoice ? (
              <Button variant="success" loading={invBusy} disabled={!invDetail || invLines.every((l) => Number(l.qty) <= 0)} onClick={createInvoice}>Simpan Invoice</Button>
            ) : (
              <Button variant="primary" loading={invBusy} onClick={matchInvoice}>Jalankan 3-way Match</Button>
            )}
          </>
        }
      >
        <div className="space-y-3">
          <label className="block text-sm">
            <span className="mb-1 block font-medium">Purchase Order *</span>
            <Select value={invPoId} onChange={(e) => selectInvPo(e.target.value)} className="w-full">
              <option value="">- pilih PO -</option>
              {invPos.map((p) => <option key={p.id} value={p.id}>{p.doc_no} ({p.status})</option>)}
            </Select>
          </label>
          {invError && <p role="alert" className="text-sm text-[var(--color-danger)]">{invError}</p>}
          {invDetail && (
            <>
              <div className="grid gap-2 sm:grid-cols-3">
                <label className="block text-sm"><span className="mb-1 block font-medium">No. Faktur Supplier</span><Input value={invNo} onChange={(e) => setInvNo(e.target.value)} /></label>
                <label className="block text-sm"><span className="mb-1 block font-medium">Tanggal Faktur *</span><Input type="date" value={invDate} onChange={(e) => setInvDate(e.target.value)} required /></label>
                <label className="block text-sm"><span className="mb-1 block font-medium">Jatuh Tempo</span><Input type="date" value={invDue} onChange={(e) => setInvDue(e.target.value)} /></label>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="border-b text-left text-xs uppercase tracking-wider text-[var(--color-text-muted)]"><th className="py-1.5 pr-2">Material</th><th className="py-1.5 pr-2 text-right">Qty PO</th><th className="py-1.5 pr-2 text-right">Diterima</th><th className="py-1.5 pr-2">Qty Faktur *</th><th className="py-1.5">Harga *</th></tr></thead>
                  <tbody>
                    {invDetail.lines.map((l, i) => (
                      <tr key={l.id} className="border-b last:border-0">
                        <td className="py-1.5 pr-2">{l.material?.code} {l.material?.name}</td>
                        <td className="py-1.5 pr-2 text-right tabular-nums">{l.qty}</td>
                        <td className="py-1.5 pr-2 text-right tabular-nums">{l.received_qty}</td>
                        <td className="py-1.5 pr-2"><Input type="number" step="any" min="0.0001" value={invLines[i]?.qty ?? ""} onChange={(e) => { const next = [...invLines]; next[i] = { ...next[i], qty: e.target.value }; setInvLines(next); }} /></td>
                        <td className="py-1.5"><Input type="number" step="any" min="0" value={invLines[i]?.unit_price ?? ""} onChange={(e) => { const next = [...invLines]; next[i] = { ...next[i], unit_price: e.target.value }; setInvLines(next); }} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
          {invoice && (
            <div className="rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-3 text-sm">
              <p className="font-medium">Invoice #{invoice.id} {invoice.doc_no ? `(${invoice.doc_no})` : ""} tersimpan.</p>
              {matchStatus && <p className="mt-1">Hasil 3-way match: <StatusBadge status={matchStatus} /></p>}
            </div>
          )}
        </div>
      </Modal>    </div>
  );
}
