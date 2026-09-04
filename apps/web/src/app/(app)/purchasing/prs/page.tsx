"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import {
  Button,
  ConfirmDialog,
  DataTable,
  FilterBar,
  FilterSelect,
  Input,
  Modal,
  PageHeader,
  Select,
  StatusBadge,
  type DataTableColumn,
} from "@/components/ui";

interface Pr {
  id: number;
  doc_no: string;
  source: string;
  status: string;
  needed_by: string | null;
  lines_count: number;
  created_at: string;
}

interface Page { data: Pr[]; total: number }
interface Opt { id: number; code: string; name?: string }
interface PrLine { material_id: string; qty: string; uom_id: string }

const blankLine = (): PrLine => ({ material_id: "", qty: "", uom_id: "" });

/** Daftar PR - termasuk yang dihasilkan dari MRP (source=MRP, BR-045/120) */
export default function PurchaseRequestsPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [source, setSource] = useState("");
  const [loading, setLoading] = useState(true);

  // Create manual
  const [createOpen, setCreateOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [neededBy, setNeededBy] = useState("");
  const [notes, setNotes] = useState("");
  const [lines, setLines] = useState<PrLine[]>([blankLine()]);
  const [materials, setMaterials] = useState<Opt[]>([]);
  const [uoms, setUoms] = useState<Opt[]>([]);

  // Submit per baris
  const [confirming, setConfirming] = useState<Pr | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const load = useCallback(() => {
    setLoading(true); setError(null);
    api.get<Page>(`/purchasing/prs${source ? `?source=${source}` : ""}`)
      .then(setPage)
      .catch((requestError) => setError(requestError.message))
      .finally(() => setLoading(false));
  }, [source]);

  useEffect(load, [load]);

  useEffect(() => {
    api.get<{ data: Opt[] }>("/master/materials?per_page=500").then((r) => setMaterials(r.data)).catch(() => {});
    api.get<{ data: Opt[] }>("/master/uoms?per_page=100").then((r) => setUoms(r.data)).catch(() => {});
  }, []);

  async function createPr() {
    setCreating(true); setError(null); setMessage(null);
    try {
      const pr = await api.post<{ id: number; doc_no: string }>("/purchasing/prs", {
        needed_by: neededBy || undefined,
        notes: notes || undefined,
        lines: lines.map((l) => ({
          material_id: Number(l.material_id),
          qty: Number(l.qty),
          uom_id: Number(l.uom_id),
        })),
      });
      setMessage(`PR ${pr.doc_no} dibuat dengan status DRAFT - ajukan untuk masuk approval.`);
      setCreateOpen(false); setNeededBy(""); setNotes(""); setLines([blankLine()]);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal membuat PR");
    } finally { setCreating(false); }
  }

  async function submitPr() {
    if (!confirming) return;
    setSubmitting(true); setError(null); setMessage(null);
    try {
      await api.post(`/purchasing/prs/${confirming.id}/submit`, {});
      setMessage(`PR ${confirming.doc_no} diajukan ke approval.`);
      setConfirming(null);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal mengajukan PR");
      setConfirming(null);
    } finally { setSubmitting(false); }
  }

  const columns: DataTableColumn<Pr>[] = [
    { key: "document", header: "No. PR", cell: (pr) => <span className="font-mono font-semibold">{pr.doc_no}</span> },
    { key: "source", header: "Sumber", cell: (pr) => <StatusBadge status={pr.source} /> },
    { key: "needed", header: "Dibutuhkan", cell: (pr) => pr.needed_by ?? "-" },
    { key: "lines", header: "Lines", align: "right", cell: (pr) => <span className="tabular-nums">{pr.lines_count}</span> },
    { key: "status", header: "Status", cell: (pr) => <StatusBadge status={pr.status} /> },
    { key: "created", header: "Dibuat", cell: (pr) => new Date(pr.created_at).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) },
    {
      key: "aksi",
      header: "Aksi",
      cell: (pr) =>
        pr.status === "DRAFT" ? (
          <Button size="sm" variant="secondary" onClick={() => setConfirming(pr)}>Ajukan</Button>
        ) : (
          <span className="text-xs text-[var(--color-text-muted)]">-</span>
        ),
    },
  ];

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Purchasing"
        title="Purchase Request"
        description="Pantau kebutuhan pembelian manual dan hasil perencanaan MRP."
        actions={<Button size="sm" onClick={() => setCreateOpen(true)}>+ Buat PR</Button>}
      />

      <FilterBar summary={page ? `${page.total} purchase request` : undefined}>
        <FilterSelect label="Sumber" value={source} onChange={(event) => setSource(event.target.value)}>
          <option value="">Semua sumber</option>
          <option value="MANUAL">Manual</option>
          <option value="MRP">Dari MRP</option>
        </FilterSelect>
      </FilterBar>

      {message && (
        <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">
          {message}
        </p>
      )}

      <DataTable
        caption="Daftar purchase request"
        columns={columns}
        rows={page?.data ?? []}
        getRowKey={(pr) => pr.id}
        loading={loading}
        error={error}
        onRetry={load}
        emptyTitle="Belum ada purchase request"
        emptyDescription={source ? "Tidak ada purchase request untuk sumber yang dipilih." : "Purchase request manual dan MRP akan muncul di sini."}
        minWidth="900px"
        mobileCard={(pr) => (
          <article className="space-y-3 p-4">
            <div className="flex items-start justify-between gap-3"><div><p className="font-mono font-semibold">{pr.doc_no}</p><p className="text-xs text-[var(--color-text-muted)]">{pr.lines_count} lines - dibutuhkan {pr.needed_by ?? "-"}</p></div><StatusBadge status={pr.status} /></div>
            <div className="flex items-center justify-between text-xs"><StatusBadge status={pr.source} /><span className="text-[var(--color-text-muted)]">{new Date(pr.created_at).toLocaleDateString("id-ID")}</span></div>
            {pr.status === "DRAFT" && (
              <div className="flex justify-end">
                <Button size="sm" variant="secondary" onClick={() => setConfirming(pr)}>Ajukan</Button>
              </div>
            )}
          </article>
        )}
      />

      <Modal
        open={createOpen}
        title="Buat Purchase Request"
        description="PR manual dibuat dengan status DRAFT dan harus diajukan sebelum masuk approval."
        size="lg"
        onClose={() => { if (!creating) setCreateOpen(false); }}
        closeDisabled={creating}
        footer={
          <>
            <Button onClick={() => setCreateOpen(false)} disabled={creating}>Batal</Button>
            <Button variant="success" loading={creating} disabled={!lines.some((l) => l.material_id && l.qty && l.uom_id)} onClick={createPr}>
              Simpan PR
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm">
              <span className="mb-1 block font-medium">Dibutuhkan (tanggal)</span>
              <Input type="date" value={neededBy} onChange={(e) => setNeededBy(e.target.value)} />
            </label>
            <label className="block text-sm">
              <span className="mb-1 block font-medium">Catatan</span>
              <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="opsional" />
            </label>
          </div>
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <p className="text-sm font-medium">Baris kebutuhan *</p>
              <Button size="sm" variant="ghost" onClick={() => setLines([...lines, blankLine()])}>+ Baris</Button>
            </div>
            {lines.map((l, i) => (
              <div key={i} className="grid grid-cols-[1fr_120px_120px_40px] items-end gap-2 rounded-[var(--radius-control)] border bg-[var(--color-surface-subtle)] p-3">
                <label className="block text-xs">
                  <span className="mb-1 block font-medium">Material *</span>
                  <Select value={l.material_id} onChange={(e) => { const next = [...lines]; next[i] = { ...l, material_id: e.target.value }; setLines(next); }} required>
                    <option value="">- pilih -</option>
                    {materials.map((m) => <option key={m.id} value={m.id}>{m.code} - {m.name ?? ""}</option>)}
                  </Select>
                </label>
                <label className="block text-xs">
                  <span className="mb-1 block font-medium">Qty *</span>
                  <Input type="number" step="any" min="0.0001" value={l.qty} onChange={(e) => { const next = [...lines]; next[i] = { ...l, qty: e.target.value }; setLines(next); }} required />
                </label>
                <label className="block text-xs">
                  <span className="mb-1 block font-medium">UOM *</span>
                  <Select value={l.uom_id} onChange={(e) => { const next = [...lines]; next[i] = { ...l, uom_id: e.target.value }; setLines(next); }} required>
                    <option value="">- pilih -</option>
                    {uoms.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
                  </Select>
                </label>
                <button
                  type="button"
                  onClick={() => setLines(lines.filter((_, x) => x !== i))}
                  disabled={lines.length === 1}
                  className="pb-2 text-sm text-[var(--color-danger)] disabled:opacity-30"
                  aria-label="Hapus baris"
                >
                  x
                </button>
              </div>
            ))}
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={confirming !== null}
        title="Ajukan PR?"
        description={confirming ? `PR ${confirming.doc_no} akan dikirim ke approval dan tidak bisa diedit lagi.` : ""}
        confirmLabel="Ajukan"
        variant="primary"
        loading={submitting}
        onConfirm={submitPr}
        onCancel={() => setConfirming(null)}
      />
    </div>
  );
}