"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

interface Role { id: number; code: string; name: string }
interface FlowStep { step_no: number; role?: Role; min_value?: number | null; max_value?: number | null }
interface Flow { id: number; doc_type: string; version: number; mode: string; is_active: boolean; steps: FlowStep[] }

const DOC_TYPES = ["SO", "PR", "PO", "BOM", "ROUTING", "COST", "MO", "ADJ", "OPN"];

/** Setup approval flow per doc_type (BR-015) — versi baru menonaktifkan versi lama */
export default function ApprovalFlowsPage() {
  const [flows, setFlows] = useState<Flow[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [docType, setDocType] = useState("SO");
  const [steps, setSteps] = useState<{ role_id: string; min_value: string; max_value: string }[]>([{ role_id: "", min_value: "", max_value: "" }]);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    api.get<{ data: Flow[] }>("/approvals/flows").then((r) => setFlows(r.data)).catch((e) => setError(e.message));
    api.get<{ data: Role[] }>("/approvals/roles").then((r) => setRoles(r.data)).catch(() => {});
  }

  useEffect(load, []);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null); setMessage(null);
    try {
      const flow = await api.post<Flow>("/approvals/flows", {
        doc_type: docType,
        mode: "sequential",
        steps: steps.map((s) => ({
          role_id: Number(s.role_id),
          min_value: s.min_value ? Number(s.min_value) : undefined,
          max_value: s.max_value ? Number(s.max_value) : undefined,
        })),
      });
      setMessage(`Flow ${flow.doc_type} v${flow.version} aktif (${flow.steps.length} step).`);
      setSteps([{ role_id: "", min_value: "", max_value: "" }]);
      load();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function deactivate(id: number) {
    if (!window.confirm("Nonaktifkan flow ini? Dokumen baru doc_type ini tidak bisa disubmit sampai ada flow aktif.")) return;
    setBusy(true); setError(null);
    try {
      await api.post(`/approvals/flows/${id}/deactivate`, {});
      load();
    } catch (e: any) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }

  const input = "w-full rounded border px-2 py-1.5 text-sm";

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-bold">Approval Flow <span className="text-sm font-normal text-slate-500">(per doc type — BR-015)</span></h1>

      {error && <pre className="whitespace-pre-wrap rounded bg-red-50 p-3 text-sm text-red-700">{error}</pre>}
      {message && <p className="rounded bg-green-50 p-3 text-sm text-green-700">{message}</p>}

      <form onSubmit={save} className="space-y-3 rounded-xl border bg-white p-4">
        <h2 className="font-semibold">Flow baru (versi baru otomatis menggantikan yang aktif)</h2>
        <div className="flex items-center gap-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Doc Type *</span>
            <select value={docType} onChange={(e) => setDocType(e.target.value)} className={input}>
              {DOC_TYPES.map((d) => <option key={d}>{d}</option>)}
            </select>
          </label>
          <span className="text-xs text-slate-500">Mode: sequential (step 1 → 2 → …)</span>
        </div>

        <div className="space-y-2">
          {steps.map((s, i) => (
            <div key={i} className="flex items-end gap-2 rounded-lg border bg-slate-50 p-3">
              <span className="w-10 text-center font-mono text-sm font-bold text-slate-500">#{i + 1}</span>
              <label className="flex-1 text-xs">
                <span className="mb-1 block font-medium">Role approver</span>
                <select value={s.role_id} onChange={(e) => { const n = [...steps]; n[i].role_id = e.target.value; setSteps(n); }} required className={input}>
                  <option value="">— pilih role —</option>
                  {roles.map((r) => <option key={r.id} value={r.id}>{r.name} ({r.code})</option>)}
                </select>
              </label>
              <label className="w-32 text-xs">
                <span className="mb-1 block font-medium">Min nilai (opsional)</span>
                <input type="number" step="any" min="0" value={s.min_value} onChange={(e) => { const n = [...steps]; n[i].min_value = e.target.value; setSteps(n); }} className={input} />
              </label>
              <label className="w-32 text-xs">
                <span className="mb-1 block font-medium">Max nilai (opsional)</span>
                <input type="number" step="any" min="0" value={s.max_value} onChange={(e) => { const n = [...steps]; n[i].max_value = e.target.value; setSteps(n); }} className={input} />
              </label>
              <button type="button" onClick={() => setSteps(steps.filter((_, x) => x !== i))} disabled={steps.length === 1} className="rounded border border-red-200 px-2 py-1.5 text-xs text-red-600 disabled:opacity-30">✕</button>
            </div>
          ))}
        </div>

        <div className="flex gap-2">
          <button type="button" onClick={() => setSteps([...steps, { role_id: "", min_value: "", max_value: "" }])} className="rounded border px-3 py-1.5 text-sm">+ Step</button>
          <button disabled={busy} className="rounded bg-slate-900 px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50">
            {busy ? "Menyimpan…" : "Simpan & Aktifkan"}
          </button>
        </div>
      </form>

      <section className="rounded-xl border bg-white">
        <table className="w-full text-sm">
          <thead className="border-b bg-slate-50 text-left">
            <tr>
              <th className="px-3 py-2 font-medium">Doc Type</th>
              <th className="px-3 py-2 font-medium">Versi</th>
              <th className="px-3 py-2 font-medium">Steps</th>
              <th className="px-3 py-2 font-medium">Status</th>
              <th className="px-3 py-2 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            {flows.map((f) => (
              <tr key={f.id} className="border-b last:border-0 hover:bg-slate-50">
                <td className="px-3 py-2 font-mono font-medium">{f.doc_type}</td>
                <td className="px-3 py-2">v{f.version}</td>
                <td className="px-3 py-2 text-xs">
                  {f.steps.map((s) => `${s.step_no}. ${s.role?.name ?? "?"}`).join(" → ")}
                </td>
                <td className="px-3 py-2">
                  {f.is_active
                    ? <span className="rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">AKTIF</span>
                    : <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">nonaktif</span>}
                </td>
                <td className="px-3 py-2">
                  {f.is_active && (
                    <button onClick={() => deactivate(f.id)} disabled={busy} className="rounded border border-amber-300 px-2 py-1 text-xs text-amber-700 disabled:opacity-50">
                      Nonaktifkan
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {flows.length === 0 && <tr><td colSpan={5} className="px-3 py-6 text-center text-slate-500">Belum ada flow — dokumen tidak bisa disubmit untuk approval sebelum flow dibuat.</td></tr>}
          </tbody>
        </table>
      </section>
    </div>
  );
}
