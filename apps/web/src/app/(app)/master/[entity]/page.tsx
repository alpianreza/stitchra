"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { api } from "@/lib/api";
import { masterEntities } from "@/lib/masterMeta";

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
  const [form, setForm] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [q, setQ] = useState("");

  function load(search = q) {
    api.get<Page>(`/master/${entity}${search ? `?q=${encodeURIComponent(search)}` : ""}`)
      .then(setPage)
      .catch((e) => setError(e.message));
  }

  useEffect(() => { if (meta) load(""); }, [entity]);

  if (!meta) return <p className="text-red-600">Entity tidak dikenal: {entity}</p>;

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
      await api.post(`/master/${entity}`, payload);
      setShowForm(false);
      setForm({});
      load();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">{meta.title}</h1>
        <div className="flex gap-2">
          <form onSubmit={(e) => { e.preventDefault(); load(); }}>
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Cari kode/nama…"
              className="rounded border px-3 py-1.5 text-sm"
            />
          </form>
          <button onClick={() => setShowForm(true)} className="rounded bg-slate-900 px-3 py-1.5 text-sm font-medium text-white">
            + Tambah
          </button>
        </div>
      </div>

      {error && <p className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {showForm && (
        <form onSubmit={save} className="rounded-xl border bg-white p-4">
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
                    <option value="">— pilih —</option>
                    {f.options!.map((o) => <option key={o} value={o}>{o}</option>)}
                  </select>
                ) : (
                  <input
                    type={f.type === "number" ? "number" : "text"}
                    step="any"
                    value={form[f.name] ?? ""}
                    onChange={(e) => setForm({ ...form, [f.name]: e.target.value })}
                    required={f.required}
                    className="w-full rounded border px-2 py-1.5"
                  />
                )}
              </label>
            ))}
          </div>
          <div className="mt-4 flex gap-2">
            <button disabled={saving} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
              {saving ? "Menyimpan…" : "Simpan"}
            </button>
            <button type="button" onClick={() => setShowForm(false)} className="rounded border px-4 py-1.5 text-sm">Batal</button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              {meta.listColumns.map((c) => <th key={c.key} className="px-3 py-2 font-medium">{c.label}</th>)}
            </tr>
          </thead>
          <tbody>
            {(page?.data ?? []).map((row) => (
              <tr key={row.id} className="border-b last:border-0 hover:bg-slate-50">
                {meta.listColumns.map((c) => (
                  <td key={c.key} className="px-3 py-2">
                    {typeof row[c.key] === "boolean" ? (row[c.key] ? "Ya" : "Tidak") : (row[c.key] ?? "—")}
                  </td>
                ))}
              </tr>
            ))}
            {page && page.data.length === 0 && (
              <tr><td colSpan={meta.listColumns.length} className="px-3 py-6 text-center text-slate-500">Belum ada data.</td></tr>
            )}
          </tbody>
        </table>
      </div>
      {page && <p className="text-xs text-slate-500">{page.total} data</p>}
    </div>
  );
}
