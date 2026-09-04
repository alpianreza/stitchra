"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button, DataTable, FilterBar, FilterSelect, Modal, PageHeader, Select, StatusBadge, type DataTableColumn } from "@/components/ui";

interface Mo { id: number; doc_no: string; status: string; qty_planned: string; qty_produced: string; style?: { style_no: string }; sales_order?: { doc_no: string }; line?: { name: string } | null }
interface Page { data: Mo[]; total: number }
interface So { id: number; doc_no: string; status: string }

const STATUSES = ["PLANNED", "RELEASED", "CUTTING", "SEWING", "FINISHING", "QC", "PACKED", "CLOSED"];
const fmt = (v: string) => Number(v).toLocaleString("id-ID", { maximumFractionDigits: 2 });

export default function ProductionOrdersPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);

  // Buat MO dari Sales Order
  const [createOpen, setCreateOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [sos, setSos] = useState<So[]>([]);
  const [fromSo, setFromSo] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    api.get<Page>(`/production/orders${status ? `?status=${status}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(load, [load]);

  useEffect(() => {
    if (createOpen) {
      api.get<{ data: So[] }>("/sales/orders?status=CONFIRMED&per_page=100")
        .then((r) => setSos(r.data))
        .catch(() => {});
    }
  }, [createOpen]);

  async function createFromSo() {
    if (!fromSo) return;
    setCreating(true); setError(null); setMessage(null);
    try {
      const r = await api.post<{ count: number; data: Mo[] }>(`/production/orders/from-so/${fromSo}`, {});
      setMessage(`${r.count} manufacturing order dibuat dari SO #${fromSo}.`);
      setCreateOpen(false); setFromSo("");
      load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Gagal membuat MO dari SO");
    } finally { setCreating(false); }
  }

  const columns: DataTableColumn<Mo>[] = [
    { key: "doc_no", header: "No. MO", cell: (mo) => <Link href={`/production/orders/${mo.id}`} className="font-mono font-semibold text-[var(--color-primary)]">{mo.doc_no}</Link> },
    { key: "so", header: "SO", cell: (mo) => mo.sales_order?.doc_no ?? "-" },
    { key: "style", header: "Style", cell: (mo) => mo.style?.style_no ?? "-" },
    { key: "planned", header: "Qty Plan", align: "right", cell: (mo) => fmt(mo.qty_planned) },
    { key: "produced", header: "qty_produced (Legacy)", align: "right", cell: (mo) => <span className="text-amber-700" title="Bukan authority/fallback">{fmt(mo.qty_produced)}</span> },
    { key: "authority", header: "Output Policy", cell: () => <span className="rounded bg-emerald-100 px-2 py-1 text-xs text-emerald-800">BR-065 - Named Measures</span> },
    { key: "status", header: "Status", cell: (mo) => <StatusBadge status={mo.status} /> },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Manufacturing"
        title="Manufacturing Order"
        description="Output memakai Separate Named Measures. qty_produced hanya legacy compatibility dan tidak dipakai sebagai fallback."
        actions={<Button size="sm" onClick={() => setCreateOpen(true)}>+ Buat MO dari SO</Button>}
      />

      <FilterBar summary={page ? `${page.total} manufacturing order` : undefined}>
        <FilterSelect label="Filter status" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">Semua status</option>
          {STATUSES.map((s) => <option key={s}>{s}</option>)}
        </FilterSelect>
      </FilterBar>

      {message && (
        <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">
          {message}
        </p>
      )}

      <DataTable
        caption="Daftar manufacturing order"
        columns={columns}
        rows={page?.data ?? []}
        getRowKey={(mo) => mo.id}
        loading={loading}
        error={error}
        onRetry={load}
        emptyTitle="Belum ada manufacturing order"
        minWidth="1050px"
        mobileCard={(mo) => (
          <Link href={`/production/orders/${mo.id}`} className="block p-4">
            <b>{mo.doc_no}</b>
            <p>{mo.status} - BR-065 Named Measures</p>
          </Link>
        )}
      />

      <Modal
        open={createOpen}
        title="Buat MO dari Sales Order"
        description="Hanya SO berstatus CONFIRMED yang bisa diproses. Sistem membuat MO per line SO."
        size="md"
        onClose={() => { if (!creating) setCreateOpen(false); }}
        closeDisabled={creating}
        footer={
          <>
            <Button onClick={() => setCreateOpen(false)} disabled={creating}>Batal</Button>
            <Button variant="success" loading={creating} disabled={!fromSo} onClick={createFromSo}>Buat MO</Button>
          </>
        }
      >
        <label className="block text-sm">
          <span className="mb-1 block font-medium">Sales Order (CONFIRMED) *</span>
          <Select value={fromSo} onChange={(e) => setFromSo(e.target.value)} className="w-full">
            <option value="">- pilih SO -</option>
            {sos.map((so) => (
              <option key={so.id} value={so.id}>{so.doc_no}</option>
            ))}
          </Select>
        </label>
        {sos.length === 0 && (
          <p className="mt-2 text-xs text-[var(--color-text-muted)]">Tidak ada SO berstatus CONFIRMED saat ini.</p>
        )}
      </Modal>
    </div>
  );
}