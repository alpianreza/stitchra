"use client";

import { useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import { api, apiUpload } from "@/lib/api";
import { masterEntities } from "@/lib/masterMeta";
import { Button, ConfirmDialog, Input, PageHeader } from "@/components/ui";

interface Page {
  data: Record<string, any>[];
  current_page: number;
  last_page: number;
  total: number;
}

export default function MasterEntityPage() {
  const { entity } = useParams<{ entity: string }>();
  const meta = masterEntities[entity];

  const [page, setPage] = useState<Page | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [q, setQ] = useState("");
  const [deleting, setDeleting] = useState<Record<string, any> | null>(null);
  const [deletingBusy, setDeletingBusy] = useState(false);
  const [importing, setImporting] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  function load(search = q) {
    api.get<Page>(`/master/${entity}${search ? `?q=${encodeURIComponent(search)}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e instanceof Error ? e.message : "Gagal memuat data"));
  }

  useEffect(() => { if (meta) load(""); }, [entity]);

  if (!meta) return <p className="text-[var(--color-danger)]">Entity tidak dikenal: {entity}</p>;

  function openCreate() {
    setEditingId(null);
    setForm({});
    setShowForm(true);
  }

  function openEdit(row: Record<string, any>) {
    const next: Record<string, string> = {};
    for (const f of meta.fields) {
      const v = row[f.name];
      next[f.name] = v === null || v === undefined ? "" : String(v);
    }
    setEditingId(Number(row.id));
    setForm(next);
    setShowForm(true);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const payload: Record<string, unknown> = {};
      for (const f of meta.fields) {
        const v = form[f.name];
        if (v === undefined || v === "") continue;
        payload[f.name] = f.type === "number" ? Number(v) : v;
      }
      if (editingId !== null) {
        await api.put(`/master/${entity}/${editingId}`, payload);
      } else {
        await api.post(`/master/${entity}`, payload);
      }
      setShowForm(false);
      setEditingId(null);
      setForm({});
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menyimpan data");
    } finally {
      setSaving(false);
    }
  }

  async function doDelete() {
    if (!deleting) return;
    setDeletingBusy(true);
    setError(null);
    try {
      await api.delete(`/master/${entity}/${deleting.id}`);
      setDeleting(null);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Gagal menghapus data");
      setDeleting(null);
    } finally {
      setDeletingBusy(false);
    }
  }

  async function onImportFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    setImporting(true); setError(null); setMessage(null);
    try {
      const form = new FormData();
      form.append("file", file);
      const job = await apiUpload<{ total_rows?: number; success_rows?: number; failed_rows?: number }>(`/master/${entity}/import`, form);
      setMessage(`Import selesai: ${job.success_rows ?? 0} sukses, ${job.failed_rows ?? 0} gagal dari ${job.total_rows ?? 0} baris.`);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Import gagal");
    } finally {
      setImporting(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  }

  return (
    <div className="space-y-4">
      <PageHeader
        eyebrow="Master Data"
        title={meta.title}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <form onSubmit={(e) => { e.preventDefault(); load(); }}>
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari kode/nama..." className="w-56" />
            </form>
            <Button size="sm" onClick={openCreate}>+ Tambah</Button>
            <Button size="sm" variant="secondary" loading={importing} onClick={() => fileRef.current?.click()}>Import CSV</Button>
            <input ref={fileRef} type="file" accept=".csv,.txt" className="hidden" onChange={onImportFile} aria-label="Pilih file CSV" />
          </div>
        }
      />

      {message && (
        <p role="status" className="rounded-[var(--radius-surface)] bg-[var(--color-success-soft)] p-3 text-sm text-[var(--color-success)]">
          {message}
        </p>
      )}

      {error && (
        <div role="alert" className="rounded-[var(--radius-surface)] border border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)]/40 p-3 text-sm text-[var(--color-danger)]">
          {error}
        </div>
      )}

      {showForm && (
        <form onSubmit={save} className="rounded-[var(--radius-surface)] border bg-white p-4 shadow-[var(--shadow-raised)]">
          <h2 className="mb-3 font-semibold">{editingId !== null ? `Edit ${meta.title}` : `Tambah ${meta.title}`}</h2>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
            {meta.fields.map((f) => (
              <label key={f.name} className="block text-sm">
                <span className="mb-1 block font-medium">{f.label}{f.required && " *"}</span>
                {f.type === "select" ? (
                  <select
                    value={form[f.name] ?? ""}
                    onChange={(e) => setForm({ ...form, [f.name]: e.target.value })}
                    required={f.required}
                    className="w-full rounded border px-2 py-1.5"
                  >
                    <option value="">- pilih -</option>
                    {f.options!.map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                ) : (
                  <Input
                    type={f.type === "number" ? "number" : "text"}
                    step="any"
                    value={form[f.name] ?? ""}
                    onChange={(e) => setForm({ ...form, [f.name]: e.target.value })}
                    required={f.required}
                  />
                )}
              </label>
            ))}
          </div>
          <div className="mt-4 flex gap-2">
            <Button type="submit" loading={saving} disabled={saving}>
              {editingId !== null ? "Simpan Perubahan" : "Simpan"}
            </Button>
            <Button type="button" variant="ghost" onClick={() => { setShowForm(false); setEditingId(null); }}>
              Batal
            </Button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-[var(--radius-surface)] border bg-white shadow-[var(--shadow-raised)]">
        <table className="w-full text-sm">
          <thead className="border-b bg-[var(--color-surface-subtle)] text-left">
            <tr>
              {meta.listColumns.map((c) => <th key={c.key} className="px-3 py-2 font-medium">{c.label}</th>)}
              <th className="px-3 py-2 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {(page?.data ?? []).map((row) => (
              <tr key={row.id} className="border-b last:border-0 hover:bg-[var(--color-surface-subtle)]">
                {meta.listColumns.map((c) => (
                  <td key={c.key} className="px-3 py-2">
                    {typeof row[c.key] === "boolean" ? (row[c.key] ? "Ya" : "Tidak") : (row[c.key] ?? "-")}
                  </td>
                ))}
                <td className="px-3 py-2 text-right">
                  <div className="inline-flex gap-1.5">
                    <Button size="sm" variant="secondary" onClick={() => openEdit(row)}>Edit</Button>
                    <Button size="sm" variant="danger" onClick={() => setDeleting(row)}>Hapus</Button>
                  </div>
                </td>
              </tr>
            ))}
            {page && page.data.length === 0 && (
              <tr><td colSpan={meta.listColumns.length + 1} className="px-3 py-6 text-center text-[var(--color-text-muted)]">Belum ada data.</td></tr>
            )}
          </tbody>
        </table>
      </div>
      {page && <p className="text-xs text-[var(--color-text-muted)]">{page.total} data</p>}

      <ConfirmDialog
        open={deleting !== null}
        title={`Hapus ${meta.title}?`}
        description={deleting ? `Data ${deleting.doc_no ?? deleting.code ?? deleting.nik ?? `#${deleting.id}`} akan dihapus. Jika sedang dipakai transaksi, backend akan menolak dengan pesan yang jelas.` : ""}
        confirmLabel="Hapus"
        variant="danger"
        loading={deletingBusy}
        onConfirm={doDelete}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}